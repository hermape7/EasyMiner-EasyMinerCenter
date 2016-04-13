<?php

namespace EasyMinerCenter\Model\Data\Databases\DataService;

use EasyMinerCenter\Exceptions\EntityNotFoundException;
use EasyMinerCenter\Model\Data\Databases\IDatabase;
use EasyMinerCenter\Model\Data\Entities\DbField;
use EasyMinerCenter\Model\Data\Entities\DbDatasource;
use EasyMinerCenter\Model\Data\Entities\DbConnection;
use Nette\Utils\Json;
use Nette\Utils\Strings;

/**
 * Class DataServiceDatabase - třída zajišťující přístup k databázím dostupným prostřednictvím služby EasyMiner-Data
 *
 * @package EasyMinerCenter\Model\Data\Databases
 * @author Stanislav Vojíř
 */
abstract class DataServiceDatabase implements IDatabase {
  /** @var  string $apiKey */
  private $apiKey;
  /** @var  DbConnection $dbConnection */
  private $dbConnection;

  /**
   * Funkce vracející seznam datových zdrojů v DB
   *
   * @return DbDatasource[]
   */
  public function getDbDatasources() {
    $responseData=$this->curlRequestResponse($this->getRequestUrl('/datasource'),null,'GET',['Accept'=>'application/json; charset=utf8'], $responseCode);
    $responseData=Json::decode($responseData, Json::FORCE_ARRAY);

    $result=[];
    if (!empty($responseData) && $responseCode==200){
      foreach($responseData as $item){
        $result[]=new DbDatasource($item['id'],$item['name'],$item['type'],$item['size']);
      }
    }
    return $result;
  }

  /**
   * Funkce vracející informace o konkrétním datovém zdroji
   *
   * @param int $datasourceId
   * @return DbDatasource
   * @throws EntityNotFoundException
   */
  public function getDbDatasource($datasourceId) {
    try{
      $responseData=$this->curlRequestResponse($this->getRequestUrl('/datasource/'.$datasourceId),null,'GET',['Accept'=>'application/json; charset=utf8'], $responseCode);
      $responseData=Json::decode($responseData, Json::FORCE_ARRAY);

      if (!empty($responseData) && ($responseCode==200)){
        return new DbDatasource($responseData['id'],$responseData['name'],$responseData['type'],$responseData['size']);
      }else{
        throw new \Exception('responseCode: '.$responseCode);
      }
    }catch (\Exception $e){
      throw new EntityNotFoundException('Requested DbDatasource was not found.');
    }
  }

  /**
   * Funkce vracející seznam sloupců v datovém zdroji
   *
   * @param DbDatasource $dbDatasource
   * @return DbField[]
   * @throws EntityNotFoundException
   * @throws \Exception
   */
  public function getDbFields(DbDatasource $dbDatasource) {
    $responseData=$this->curlRequestResponse($this->getRequestUrl('/datasource/'.$dbDatasource->id.'/field'),null,'GET',['Accept'=>'application/json; charset=utf8'], $responseCode);
    if ($responseCode==200){
      $responseData=Json::decode($responseData, Json::FORCE_ARRAY);
      $result=[];
      if (!empty($responseData)){
        foreach($responseData as $responseField){
          $result[]=new DbField($responseField['id'], $responseField['dataSource'], $responseField['name'], $responseField['type'], $responseField['uniqueValuesSize']);
        }
      }
      return $result;
    }
    throw new EntityNotFoundException('Requested DbDatasource was not found.');
  }

  /**
   * Funkce pro přejmenování datového sloupce
   * @param DbField $dbField
   * @param string $newName='' (pokud není název vyplněn, je převzat název z DbField
   * @return bool
   */
  public function renameDbField(DbField $dbField, $newName=''){
    $newName=trim($newName);
    if (!$newName){
      $newName=$dbField->name;
    }
    $this->curlRequestResponse($this->getRequestUrl('/datasource/'.$dbField->dataSource.'/field/'.$dbField->id),$newName,'PUT',['Content-Type'=>'text/plain;charset=utf-8'], $responseCode);
    return ($responseCode==200);
  }


  /**
   * Konstruktor zajišťující připojení k databázi
   *
   * @param DbConnection $dbConnection
   * @param string $apiKey
   */
  public function __construct(DbConnection $dbConnection, $apiKey) {
    $this->dbConnection=$dbConnection;
    $this->dbConnection->dbServer=rtrim($this->dbConnection->dbServer,'/');//nechceme lomítko na konci
    $this->apiKey=$apiKey;
  }

  #region funkce pro práci s RESTFUL API
  /**
   * Funkce vracející URL pro odeslání požadavku na datovou službu
   *
   * @param string $relativeUrl
   * @return string
   */
  private function getRequestUrl($relativeUrl){
    $url=$this->dbConnection->dbApi;
    if (Strings::endsWith($url,'/')){
      $url=rtrim($url,'/');
    }
    return $url.$relativeUrl;
  }

  /**
   * Funkce pro práci s RESTFUL API
   *
   * @param string $url
   * @param string $postData = ''
   * @param string|null $method = 'GET'
   * @param array $headersArr=[]
   * @param int|null &$responseCode - proměnná pro vrácení stavového kódu odpovědi
   * @return string - response data
   * @throws \Exception - curl error
   */
  private function curlRequestResponse($url, $postData='', $method='GET', $headersArr=[], &$responseCode=null){
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch,CURLOPT_MAXREDIRS,0);
    curl_setopt($ch,CURLOPT_FOLLOWLOCATION,false);
    if (empty($headersArr['Content-Type']) & !empty($postData)){
      $headersArr['Content-Type']='application/xml; charset=utf-8';
    }
    if (!empty($this->apiKey)){
      $headersArr['Authorization']='ApiKey '.$this->apiKey;
    }
    if ($postData!=''){
      curl_setopt($ch,CURLOPT_POST,true);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, ($method?$method:"POST"));
      curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
      $headersArr['Content-length']=strlen($postData);
    }else{
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, ($method?$method:"GET"));
    }

    $httpHeadersArr=[];
    if (!empty($headersArr)){
      foreach($headersArr as $header=>$value){
        $httpHeadersArr[]=$header.': '.$value;
      }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeadersArr);

    $responseData = curl_exec($ch);
    $responseCode=curl_getinfo($ch,CURLINFO_HTTP_CODE);

    if(curl_errno($ch)){
      $exception=curl_error($ch);
      curl_close($ch);
      throw new \Exception($exception);
    }
    curl_close($ch);
    return $responseData;
  }
  #endregion funkce pro práci s RESTFUL API

}