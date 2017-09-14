<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Created by arsan-irianto
 * Date: 09/09/2017
 * Time: 12.14
 */
use \LINE\LINEBot;
use \LINE\LINEBot\HTTPClient\CurlHTTPClient;
use \LINE\LINEBot\MessageBuilder\MultiMessageBuilder;
use \LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use \LINE\LINEBot\MessageBuilder\StickerMessageBuilder;
use \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use \LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder;
use \LINE\LINEBot\MessageBuilder\TemplateBuilder\CarouselColumnTemplateBuilder;
use \LINE\LINEBot\MessageBuilder\TemplateBuilder\CarouselTemplateBuilder;
use \LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder;
use \LINE\LINEBot\MessageBuilder\LocationMessageBuilder;
use \LINE\LINEBot\TemplateActionBuilder\UriTemplateActionBuilder;
use \LINE\LINEBot\TemplateActionBuilder\PostbackTemplateActionBuilder;

class Webhook extends CI_Controller {

  private $bot;
  private $events;
  private $signature;
  private $user;
  private $resultMapArray;

  function __construct()
  {
    parent::__construct();
    $this->load->model('webhook_m');

    // create bot object
    $httpClient = new CurlHTTPClient($_ENV['CHANNEL_ACCESS_TOKEN']);
    $this->bot  = new LINEBot($httpClient, ['channelSecret' => $_ENV['CHANNEL_SECRET']]);
  }

  public function index()
  {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      echo "Hello Coders!";
      header('HTTP/1.1 400 Only POST method allowed');
      exit;
    }

    // get request
    $body = file_get_contents('php://input');
    $this->signature = isset($_SERVER['HTTP_X_LINE_SIGNATURE']) ? $_SERVER['HTTP_X_LINE_SIGNATURE'] : "-";
    // For dummy Signature on Debug mode, remove block comment for testing

    /*
    $channelSecret = $_ENV['CHANNEL_SECRET'];
    $httpRequestBody = $body;
    $hash = hash_hmac('sha256', $httpRequestBody, $channelSecret, true);
    $this->signature = base64_encode($hash);*/

    $this->events = json_decode($body, true);

    // save log every event requests
    $this->webhook_m->log_events($this->signature, $body);

    if(is_array($this->events['events'])){
      foreach ($this->events['events'] as $event){

        // skip group and room event
        if(! isset($event['source']['userId'])) continue;

        // get user data from database
        $this->user = $this->webhook_m->getUser($event['source']['userId']);

        // if user not registered
        if(!$this->user) $this->followCallback($event);
        else {
          // respond event
          if($event['type'] == 'message'){
            if(method_exists($this, $event['message']['type'].'Message')){
              $this->{$event['message']['type'].'Message'}($event);
            }

          } else {
            if(method_exists($this, $event['type'].'Callback')){
              $this->{$event['type'].'Callback'}($event);
            }
          }
        }

      } // end of foreach
    }

    // debuging data
    file_put_contents('php://stderr', 'Body: '.$body);

  } // end of index.php

  private function followCallback($event)
  {
    $res = $this->bot->getProfile($event['source']['userId']);
    if ($res->isSucceeded())
    {
      $profile = $res->getJSONDecodedBody();

      // create welcome message
      $message  = "Assalamualaykum warahmatullahi wabarakatuh...\n";
      $message .= "Hai, " . $profile['displayName'] . "!\n";
      $message .= "Terima kasih sudah menambahkan aku sebagai teman :D \n";
      $message .= "Insya Allah aku akan membantu kamu menemukan lokasi masjid terdekat, \n";
      $message .= "info buku-buku islami, artikel pilihan dan fitur-fitur menarik lainnya \n";
      $message .= "yang akan dikembangkan sesuai kebutuhan kamu sebagai seorang muslim. \n";
      $message .= "Karena itu sering-sering ya chat dengan aku :D";
      $textMessageBuilder = new TextMessageBuilder($message);

      // create sticker message
      $stickerMessageBuilder = new StickerMessageBuilder(1, 2);

      // merge all message
      $multiMessageBuilder = new MultiMessageBuilder();
      $multiMessageBuilder->add($textMessageBuilder);
      $multiMessageBuilder->add($stickerMessageBuilder);

      // send reply message
      $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);

      // save user data
      $this->webhook_m->saveUser($profile);
    }
  }

  /* gets the data from a URL */
  private function get_data($url) {
    $ch = curl_init();
    $timeout = 5;
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
  }

  /*
   * Function for debug mode & test script output
   */
  public function dummy(){
    $url ="https://maps.googleapis.com/maps/api/place/nearbysearch/json?key=AIzaSyDk0ZDDDMCFiVZUxwLsNlUPJwSiTxQzub4&location=-5.15066,119.464902&keyword=masjid&name=masjid&type=mosque&rankby=distance";

    $returned_content = $this->get_data($url);
    $this->resultMapArray = json_decode($returned_content,true);
    foreach ( $this->resultMapArray as $resultArray){
      echo $resultArray."<br>";
    }
    //print_r(json_decode($returned_content,true));
  }

  private function locationMessage($event){
    $userLocation = $event['message']['type'];
    if($userLocation == 'location'){
/*
      $urlMasjidTerdekat = "https://maps.googleapis.com/maps/api/place/nearbysearch/json?";
      $urlMasjidTerdekat.= "key=".$_ENV['GMAPS_API_KEY'];
      $urlMasjidTerdekat.= "&location=".$event['message']['latitude'].",".$event['message']['longitude'];
      $urlMasjidTerdekat.= "&keyword=masjid&name=masjid&type=mosque&rankby=distance";*/

      $urlMasjidTerdekat ="https://maps.googleapis.com/maps/api/place/nearbysearch/json?";
      $urlMasjidTerdekat .="location=". $event['message']['latitude'] . "," . $event['message']['longitude'];
      $urlMasjidTerdekat .="&radius=500&type=mosque&keyword=masjid";
      $urlMasjidTerdekat .="&key=".$_ENV['GMAPS_API_KEY'];

      //$this->bot->replyText($event['replyToken'], $urlMasjidTerdekat);
      //echo json_decode(file_get_contents($urlMasjidTerdekat),true);

      $returned_content = $this->get_data($urlMasjidTerdekat);
      $result = json_decode($returned_content,true);

      $i=0;
      foreach($result['results'] as $resultItem) if ($i < 5) {
        $namaMasjid[]= $resultItem['name'];
        $alamatMasjid[] = $resultItem['vicinity'];
        $latMasjid[] = $resultItem['geometry']['location']['lat'];
        $lngMasjid[] = $resultItem['geometry']['location']['lng'];

        // Loop Photo Masjid
        $urlPhotoMasjidTerdekat[]="https://maps.googleapis.com/maps/api/place/photo?maxwidth=400";
        $urlPhotoMasjidTerdekat[].="&photoreference=".$resultItem['photos'][0]['photo_reference'];
        $urlPhotoMasjidTerdekat[].="&key=".$_ENV['GMAPS_API_KEY'];
        $i++;
      }

/*      $namaMasjid[0] = $result['results'][0]['name'];
      $alamatMasjid[0] = $result['results'][0]['vicinity'];
      $latMasjid[0] = $result['results'][0]['geometry']['location']['lat'];
      $lngMasjid[0] = $result['results'][0]['geometry']['location']['lng'];

      $namaMasjid[1] = $result['results'][1]['name'];
      $alamatMasjid[1] = $result['results'][1]['vicinity'];
      $latMasjid[1] = $result['results'][1]['geometry']['location']['lat'];
      $lngMasjid[1] = $result['results'][1]['geometry']['location']['lng'];

      $namaMasjid[2] = $result['results'][2]['name'];
      $alamatMasjid[2] = $result['results'][2]['vicinity'];
      $latMasjid[2] = $result['results'][2]['geometry']['location']['lat'];
      $lngMasjid[2] = $result['results'][2]['geometry']['location']['lng'];

      $namaMasjid[3] = $result['results'][3]['name'];
      $alamatMasjid[3] = $result['results'][3]['vicinity'];
      $latMasjid[3] = $result['results'][3]['geometry']['location']['lat'];
      $lngMasjid[3] = $result['results'][3]['geometry']['location']['lng'];

      $namaMasjid[4] = $result['results'][4]['name'];
      $alamatMasjid[4] = $result['results'][4]['vicinity'];
      $latMasjid[4] = $result['results'][4]['geometry']['location']['lat'];
      $lngMasjid[4] = $result['results'][4]['geometry']['location']['lng'];

      $urlPhotoMasjidTerdekat[0]="https://maps.googleapis.com/maps/api/place/photo?maxwidth=400";
      $urlPhotoMasjidTerdekat[0].="&photoreference=".$result['results'][0]['photos'][0]['photo_reference'];
      $urlPhotoMasjidTerdekat[0].="&key=AIzaSyDk0ZDDDMCFiVZUxwLsNlUPJwSiTxQzub4";

      $urlPhotoMasjidTerdekat[1]="https://maps.googleapis.com/maps/api/place/photo?maxwidth=400";
      $urlPhotoMasjidTerdekat[1].="&photoreference=".$result['results'][1]['photos'][0]['photo_reference'];
      $urlPhotoMasjidTerdekat[1].="&key=AIzaSyDk0ZDDDMCFiVZUxwLsNlUPJwSiTxQzub4";

      $urlPhotoMasjidTerdekat[2]="https://maps.googleapis.com/maps/api/place/photo?maxwidth=400";
      $urlPhotoMasjidTerdekat[2].="&photoreference=".$result['results'][2]['photos'][0]['photo_reference'];
      $urlPhotoMasjidTerdekat[2].="&key=AIzaSyDk0ZDDDMCFiVZUxwLsNlUPJwSiTxQzub4";

      $urlPhotoMasjidTerdekat[3]="https://maps.googleapis.com/maps/api/place/photo?maxwidth=400";
      $urlPhotoMasjidTerdekat[3].="&photoreference=".$result['results'][3]['photos'][0]['photo_reference'];
      $urlPhotoMasjidTerdekat[3].="&key=AIzaSyDk0ZDDDMCFiVZUxwLsNlUPJwSiTxQzub4";

      $urlPhotoMasjidTerdekat[4]="https://maps.googleapis.com/maps/api/place/photo?maxwidth=400";
      $urlPhotoMasjidTerdekat[4].="&photoreference=".$result['results'][4]['photos'][0]['photo_reference'];
      $urlPhotoMasjidTerdekat[4].="&key=AIzaSyDk0ZDDDMCFiVZUxwLsNlUPJwSiTxQzub4";*/

      //$location = new LocationMessageBuilder($namaMasjid, $alamatMasjid, $latMasjid, $lngMasjid);
      //$this->bot->replyMessage($event['replyToken'], $location);

      //prepare options button
      //$options[0] = new MessageTemplateActionBuilder('Detail Lokasi', 'detail lokasi');
      // prepare button template
      //$buttonTemplate = new ButtonTemplateBuilder($namaMasjid, $alamatMasjid, $urlPhotoMasjidTerdekat, $options);

      // build message
      //$messageBuilder = new TemplateMessageBuilder("Gunakan mobile app untuk melihat soal", $buttonTemplate);

      // send message
      //$this->bot->replyMessage($event['replyToken'], $messageBuilder);

/*      for($j=0; $j<=$i; $j++){

      }*/

      $carouselTemplateBuilder = new CarouselTemplateBuilder([
        new CarouselColumnTemplateBuilder($namaMasjid[0], $alamatMasjid[0], $urlPhotoMasjidTerdekat[0], [
          new UriTemplateActionBuilder('Detail Lokasi', 'https://line.me'),
        ]),
        new CarouselColumnTemplateBuilder($namaMasjid[1], $alamatMasjid[1], $urlPhotoMasjidTerdekat[1], [
          new UriTemplateActionBuilder('Detail Lokasi', 'https://line.me'),
        ]),
        new CarouselColumnTemplateBuilder($namaMasjid[2], $alamatMasjid[2], $urlPhotoMasjidTerdekat[2], [
          new UriTemplateActionBuilder('Detail Lokasi', 'https://line.me'),
        ]),
        new CarouselColumnTemplateBuilder($namaMasjid[3], $alamatMasjid[3], $urlPhotoMasjidTerdekat[3], [
          new UriTemplateActionBuilder('Detail Lokasi', 'https://line.me'),
          ]),
        new CarouselColumnTemplateBuilder($namaMasjid[4], $alamatMasjid[4], $urlPhotoMasjidTerdekat[4], [
          new UriTemplateActionBuilder('Detail Lokasi', 'https://line.me'),
        ]),
      ]);

      $templateMessage = new TemplateMessageBuilder('Gunakan mobile app untuk melihat pesan', $carouselTemplateBuilder);
      $this->bot->replyMessage($event['replyToken'], $templateMessage);

    }

  }
  private function textMessage($event)
  {
    $userMessage = $event['message']['text'];
      if(strtolower($userMessage) == 'mulai')
      {
        $location = new LocationMessageBuilder('tes', 'bontobila', '-33.8670522', '151.1957362');
        $this->bot->replyMessage($event['replyToken'], $location);
      } else {
        $message = 'Silakan kirim pesan "MULAI" untuk memulai kuis.';
        $textMessageBuilder = new TextMessageBuilder($message);
        $this->bot->replyMessage($event['replyToken'], $textMessageBuilder);
      }
  }

  private function stickerMessage($event)
  {
    // create sticker message
    $stickerMessageBuilder = new StickerMessageBuilder(1, 106);

    // create text message
    $message = 'Silakan kirim pesan "MULAI" untuk memulai kuis.';
    $textMessageBuilder = new TextMessageBuilder($message);

    // merge all message
    $multiMessageBuilder = new MultiMessageBuilder();
    $multiMessageBuilder->add($stickerMessageBuilder);
    $multiMessageBuilder->add($textMessageBuilder);
    // send message
    $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
  }

}