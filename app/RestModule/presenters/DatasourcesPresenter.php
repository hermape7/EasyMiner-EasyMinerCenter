<?php

namespace EasyMinerCenter\RestModule\Presenters;
use Drahak\Restful\Validation\IValidator;
use EasyMinerCenter\Model\Data\Facades\DatabasesFacade;
use EasyMinerCenter\Model\Data\Facades\FileImportsFacade;
use EasyMinerCenter\Model\EasyMiner\Entities\Datasource;
use EasyMinerCenter\Model\EasyMiner\Facades\DatasourcesFacade;
use Nette\Application\BadRequestException;
use Nette\Http\FileUpload;
use Nette\Utils\FileSystem;

/**
 * Class DatasourcesPresenter - presenter pro práci s datovými zdroji
 * @package EasyMinerCenter\RestModule\Presenters
 */
class DatasourcesPresenter extends BaseResourcePresenter{

  /** @var  DatasourcesFacade $datasourcesFacade */
  private $datasourcesFacade;
  /** @var  FileImportsFacade $fileImportsFacade */
  private $fileImportsFacade;
  /** @var  DatabasesFacade $databasesFacade */
  private $databasesFacade;

  /**
   * Akce pro import CSV souboru (případně komprimovaného v ZIP archívu)
   * @SWG\Post(
   *   tags={"Datasources"},
   *   path="/datasources",
   *   summary="Create new datasource using uploaded file",
   *   consumes={"text/csv"},
   *   produces={"application/json","application/xml"},
   *   security={{"apiKey":{}},{"apiKeyHeader":{}}},
   *   @SWG\Parameter(
   *     name="dbTable",
   *     description="Table name (if empty, will be auto-generated)",
   *     required=false,
   *     type="string",
   *     in="query"
   *   ),
   *   @SWG\Parameter(
   *     name="separator",
   *     description="Columns separator",
   *     required=true,
   *     type="string",
   *     in="query"
   *   ),
   *   @SWG\Parameter(
   *     name="encoding",
   *     description="File encoding",
   *     required=true,
   *     type="string",
   *     in="query",
   *     enum={"utf8","cp1250","iso-8859-1"}
   *   ),
   *   @SWG\Parameter(
   *     name="enclosure",
   *     description="Enclosure character",
   *     required=false,
   *     type="string",
   *     in="query"
   *   ),
   *   @SWG\Parameter(
   *     name="escape",
   *     description="Escape character",
   *     required=false,
   *     type="string",
   *     in="query"
   *   ),
   *   @SWG\Parameter(
   *     name="nullValue",
   *     description="Null value",
   *     required=false,
   *     type="string",
   *     in="query"
   *   ),
   *   @SWG\Parameter(
   *     name="dbType",
   *     description="Database type",
   *     required=true,
   *     type="string",
   *     enum={"mysql"},
   *     in="query"
   *   ),
   *   @SWG\Parameter(
   *     name="file",
   *     description="CSV file",
   *     required=true,
   *     type="file",
   *     in="formData"
   *   ),
   *   @SWG\Response(
   *     response=200,
   *     description="Datasource details",
   *     @SWG\Schema(
   *       ref="#/definitions/DatasourceWithColumnsResponse"
   *     )
   *   ),
   *   @SWG\Response(
   *     response=400,
   *     description="Invalid API key supplied",
   *     @SWG\Schema(ref="#/definitions/StatusResponse")
   *   )
   * )
   * @throws \InvalidArgumentException
   */
  public function actionCreate() {
    #region move uploaded file
    /** @var FileUpload $file */
    $file=$this->request->files['file'];
    //detekce typu souboru
    $fileType=$this->fileImportsFacade->detectFileType($file->getName());
    if ($fileType==FileImportsFacade::FILE_TYPE_UNKNOWN){
      //jedná se o nepodporovaný typ souboru
      try{
        FileSystem::delete($this->fileImportsFacade->getTempFilename());
      }catch (\Exception $e){}
      throw new \InvalidArgumentException('The uploaded file is not in supported format!');
    }
    //move file
    $filename=$this->fileImportsFacade->getTempFilename();
    $file->move($this->fileImportsFacade->getFilePath($filename));
    //pokus o automatickou extrakci souboru
    if ($fileType==FileImportsFacade::FILE_TYPE_ZIP){
      $fileType=$this->fileImportsFacade->tryAutoUnzipFile($filename);
      if ($fileType!=FileImportsFacade::FILE_TYPE_CSV){
        try{
          FileSystem::delete($this->fileImportsFacade->getFilePath($filename));
        }catch (\Exception $e){}
        throw new \InvalidArgumentException('The uploaded ZIP file has to contain only one CSV file!');
      }
    }
    #endregion move uploaded file

    /** @var array $inputData */
    $inputData=$this->input->getData();
    //prepare default values
    if (empty($inputData['dbTable'])){
      $inputData['dbTable']=FileImportsFacade::sanitizeFileNameForImport($file->sanitizedName);
    }else{
      $inputData['dbTable']=FileImportsFacade::sanitizeFileNameForImport($inputData['dbTable']);
    }
    if (empty($inputData['enclosure'])){
      $inputData['enclosure']='"';
    }
    if (empty($inputData['escape'])){
      $inputData['escape']='\\';
    }
    if (empty($inputData['nullValue'])){
      $inputData['nullValue']='';
    }

    //prepare new datasource
    /** @var Datasource $datasource */
    $datasource=$this->datasourcesFacade->prepareNewDatasourceForUser($this->getCurrentUser(),$inputData['dbType']);
    $this->databasesFacade->openDatabase($datasource->getDbConnection());
    $inputData['dbTable']=$this->databasesFacade->prepareNewTableName($inputData['dbTable']);
    $this->fileImportsFacade->importCsvFile($filename,$datasource->getDbConnection(),$inputData['dbTable'],$inputData['encoding'],$inputData['separator'],$inputData['enclosure'],$inputData['escape'],$inputData['nullValue']);
    $this->datasourcesFacade->saveDatasource($datasource);
    //send response
    $this->actionRead($datasource->datasourceId);
  }

  /**
   * Funkce pro validaci vstupních hodnot
   * @throws \Drahak\Restful\Application\BadRequestException
   */
  public function validateCreate() {
    $this->input->field('dbType')
      ->addRule(IValidator::REQUIRED,'You have to select database type!');
    $this->input->field('separator')
      ->addRule(IValidator::REQUIRED,'Separator character is required!')
      ->addRule(IValidator::LENGTH,'Separator has to be one character!',[1,1]);
    $this->input->field('encoding')
      ->addRule(IValidator::REQUIRED,'You have to select file encoding!');
    $this->input->field('enclosure')
      ->addRule(IValidator::MAX_LENGTH,'Separator has to be one character!',1);
    $this->input->field('escape')
      ->addRule(IValidator::MAX_LENGTH,'Separator has to be one character!',1);
    if (empty($this->request->files['file'])){
      throw new \Drahak\Restful\Application\BadRequestException('You have to upload a file!');
    }
  }

  #region actionRead/actionList
  /**
   * @param int|null $id=null
   * @throws BadRequestException
   * @SWG\Get(
   *   tags={"Datasources"},
   *   path="/datasources/{id}",
   *   summary="Get data source basic details",
   *   produces={"application/json","application/xml"},
   *   security={{"apiKey":{}},{"apiKeyHeader":{}}},
   *   @SWG\Parameter(
   *     name="id",
   *     description="Datasource ID",
   *     required=true,
   *     type="integer",
   *     in="path"
   *   ),
   *   @SWG\Response(
   *     response=200,
   *     description="Datasource details",
   *     @SWG\Schema(
   *       ref="#/definitions/DatasourceWithColumnsResponse"
   *     )
   *   ),
   *   @SWG\Response(
   *     response=400,
   *     description="Invalid API key supplied",
   *     @SWG\Schema(ref="#/definitions/StatusResponse")
   *   ),
   *   @SWG\Response(response=404, description="Requested datasource was not found.")
   * )
   */
  public function actionRead($id=null) {
    if ($id==null){
      $this->forward('list');return;
    }
    $datasource=$this->findDatasourceWithCheckAccess($id);
    $result=$datasource->getDataArr();
    if (!empty($datasource->datasourceColumns)){
      foreach($datasource->datasourceColumns as $column){
        $result['column'][]=['name'=>$column->name,'type'=>$column->type];
      }
    }
    $this->resource=$result;
    $this->sendResource();
  }

  /**
   * Akce vracející seznam datových zdrojů pro aktuálního uživatele
   * @SWG\Get(
   *   tags={"Datasources"},
   *   path="/datasources",
   *   summary="Get list of datasources for the current user",
   *   security={{"apiKey":{}},{"apiKeyHeader":{}}},
   *   produces={"application/json","application/xml"},
   *   @SWG\Response(
   *     response="200",
   *     description="List of datasources",
   *     @SWG\Schema(
   *       type="array",
   *       @SWG\Items(
   *         ref="#/definitions/DatasourceBasicResponse"
   *       )
   *     )
   *   )
   * )
   */
  public function actionList() {
    $this->setXmlMapperElements('datasources','datasource');
    $currentUser=$this->getCurrentUser();
    $datasources=$this->datasourcesFacade->findDatasourcesByUser($currentUser);
    $result=[];
    if (!empty($datasources)){
      foreach ($datasources as $datasource){
        $result[]=$datasource->getDataArr();
      }
    }
    $this->resource=$result;
    $this->sendResource();
  }
  #endregion actionRead/actionList

  /**
   * Funkce pro nalezení datového zdroje s kontrolou oprávnění přístupu
   * @param int $datasourceId
   * @throws BadRequestException
   * @return Datasource
   */
  private function findDatasourceWithCheckAccess($datasourceId) {
    try{
      $datasource=$this->datasourcesFacade->findDatasource($datasourceId);
      if (!$this->datasourcesFacade->checkDatasourceAccess($datasource,$this->getCurrentUser())){
        throw new BadRequestException("You are not authorized to use the selected datasource!");
      }
    }catch (\Exception $e){
      throw new BadRequestException("Requested datasource was not found or is not accessible!");
    }
    return $datasource;
  }



  #region injections
  /**
   * @param DatasourcesFacade $datasourcesFacade
   */
  public function injectDatasourcesFacade(DatasourcesFacade $datasourcesFacade) {
    $this->datasourcesFacade=$datasourcesFacade;
  }
  /**
   * @param FileImportsFacade $fileImportsFacade
   */
  public function injectFileImportsFacade(FileImportsFacade $fileImportsFacade) {
    $this->fileImportsFacade=$fileImportsFacade;
  }
  /**
   * @param DatabasesFacade $databasesFacade
   */
  public function injectDatabasesFacade(DatabasesFacade $databasesFacade) {
    $this->databasesFacade=$databasesFacade;
  }
  #endregion injections
}

/**
 * @SWG\Definition(
 *   definition="DatasourceBasicResponse",
 *   title="DatasourceBasicInfo",
 *   required={"id","type","dbServer","dbUsername","dbName","dbTable"},
 *   @SWG\Property(property="id",type="integer",description="Unique ID of the datasource"),
 *   @SWG\Property(property="type",type="string",description="Type of the used database"),
 *   @SWG\Property(property="dbServer",type="string",description="Database server"),
 *   @SWG\Property(property="dbPort",type="integer",description="Database port"),
 *   @SWG\Property(property="dbUsername",type="string",description="Database user name"),
 *   @SWG\Property(property="dbName",type="string",description="Name of the database"),
 *   @SWG\Property(property="dbTable",type="string",description="Name of the database table"),
 * )
 * @SWG\Definition(
 *   definition="DatasourceWithColumnsResponse",
 *   title="DatasourceBasicInfo",
 *   required={"id","type","dbServer","dbUsername","dbName","dbTable"},
 *   @SWG\Property(property="id",type="integer",description="Unique ID of the datasource"),
 *   @SWG\Property(property="type",type="string",description="Type of the used database"),
 *   @SWG\Property(property="dbServer",type="string",description="Database server"),
 *   @SWG\Property(property="dbPort",type="integer",description="Database port"),
 *   @SWG\Property(property="dbUsername",type="string",description="Database user name"),
 *   @SWG\Property(property="dbName",type="string",description="Name of the database"),
 *   @SWG\Property(property="dbTable",type="string",description="Name of the database table"),
 *   @SWG\Property(property="column",type="array",
 *     @SWG\Items(ref="#/definitions/ColumnBasicInfoResponse")
 *   )
 * )
 * @SWG\Definition(
 *   definition="ColumnBasicInfoResponse",
 *   required={"name"},
 *   @SWG\Property(property="name",type="string")
 * )
 */