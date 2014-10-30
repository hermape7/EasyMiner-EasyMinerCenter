<?php
namespace App\EasyMinerModule\Presenters;

use App\Model\Data\Facades\DatabasesFacade;
use App\Model\EasyMiner\Facades\DatasourcesFacade;
use App\Model\EasyMiner\Facades\MinersFacade;
use IZI\IZIConfig;
use IZI\Parser\DataParser;
use Nette\Application\BadRequestException;

/**
 * Class IziUiPresenter - prezenter obsahující funkcionalitu vyžadovanou javascriptovým uživatelským rozhraním (migrace PHP kódu z projektu EasyMiner2)
 * @package App\EasyMinerModule\Presenters
 */
class IziUiPresenter extends BaseRestPresenter{
  private $lang='en';//TODO předávání jazyka rozhraní
  /** @var  IZIConfig $config */
  private $config;
  /** @var  MinersFacade $minersFacade */
  private $minersFacade;
  /** @var  DatasourcesFacade $datasourcesFacade*/
  private $datasourcesFacade;

  /**
   * Akce vracející data description a konfiguraci pro EasyMiner UI
   * @param int $id_dm
   * @param int $miner
   */
  public function actionGetData($id_dm,$miner){//TODO ošetření případné chyby!!!
    if (empty($miner)){
      $miner=$id_dm;
    }
    if ($id_dm=='TEST'){
      $DP = new DataParser($this->config->DDPath, $this->config->FLPath, $this->config->FGCPath, null, null, $this->lang);
      $DP->loadData();
      $responseContent = $DP->parseData();
      $responseContent['status'] = 'ok';
      $this->sendJsonResponse($responseContent);
      return;
    }

    //------------------------------------------------------------------------------------------------------------------
    if ($miner){
      $miner=$this->minersFacade->findMiner($miner);
      $this->checkMinerAccess($miner);
    }

    //------------------------------------------------------------------------------------------------------------------
    #region připravení informací pro UI - s odděleným připravením DataDictionary
    //TODO připravení DataDescriptionPMML
    $dataDescriptionPMML=null;
    $dataParser = new DataParser($dataDescriptionPMML, $this->config->FLPath, $this->config->FGCPath, null, null, $this->lang);//TODO kontrola, jestli může být obsah předán bez uložení do souboru
    $dataParser->loadData();
    $responseContent = $dataParser->parseData();

    $attributessource=null;
    try{
      $attributessource=$miner->attributessource;
    }catch (\Exception $e){/*chybu ignorujeme - zatím pravděpodobně neexistují žádné atributy*/}

    $responseContent['DD']=$this->datasourcesFacade->exportDictionariesArr($miner->datasource,$attributessource);


    #endregion připravení informací pro UI - s odděleným připravením DataDictionary


    $responseContent['status'] = 'ok';

    $this->sendJsonResponse($responseContent);
  }

  /**
   * @param IZIConfig $iziConfig
   */
  public function injectIZIConfig(IZIConfig $iziConfig){
    $this->config=$iziConfig;
  }

  /**
   * @param DatasourcesFacade $datasourcesFacade
   */
  public function injectDatasourcesFacade(DatasourcesFacade $datasourcesFacade){
    $this->datasourcesFacade=$datasourcesFacade;
  }
  /**
   * @param MinersFacade $minersFacade
   */
  public function injectMinersFacade(MinersFacade $minersFacade){
    $this->minersFacade=$minersFacade;
  }
} 