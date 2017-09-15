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
      $message .= "Insya Allah aku akan membantu kamu menemukan Masjid terdekat, \n";
      $message .= "One Click One Ayat, Jadwal Shalat dan fitur-fitur menarik lainnya \n";
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
  private function get_data($url)
  {
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

  private function locationMessage($event, $message)
  {
    $userLocation = $event['message']['type'];
    if($userLocation == 'location'){

      $locationFromUserShared = $event['message']['latitude'] . "," . $event['message']['longitude'];

      $urlMasjidTerdekat ="https://maps.googleapis.com/maps/api/place/nearbysearch/json?";
      $urlMasjidTerdekat .="location=". $event['message']['latitude'] . "," . $event['message']['longitude'];
      $urlMasjidTerdekat .="&radius=500&type=mosque&keyword=masjid";
      $urlMasjidTerdekat .="&key=".$_ENV['GMAPS_API_KEY'];

      // get url maps to parse json
      $returned_content = $this->get_data($urlMasjidTerdekat);
      // Decode google maps json
      $result = json_decode($returned_content,true);

      $columnTemplateBuilders = array();
      if(is_array($result['results'])){
        $i=0;
        foreach($result['results'] as $resultItem) if ($i < 5) {

          // Array Data Masjid
          $namaMasjid[]= $resultItem['name'];
          $alamatMasjid[] = $resultItem['vicinity'];
          $latMasjid[] = $resultItem['geometry']['location']['lat'];
          $lngMasjid[] = $resultItem['geometry']['location']['lng'];

          // Create link direction url
          $urlDirection[] = "https://www.google.co.id/maps/dir/".$locationFromUserShared."/".$resultItem['geometry']['location']['lat'].",".$resultItem['geometry']['location']['lng']."/@".$locationFromUserShared.",17z";

          // Array Photo Masjid
          $urlPhotoMasjidTerdekat[]="https://maps.googleapis.com/maps/api/place/photo?maxwidth=400&photoreference=".$resultItem['photos'][0]['photo_reference']."&key=".$_ENV['GMAPS_API_KEY'];

          // Array Column Carousel for carousel Template Builder
          $columnTemplateBuilder = new CarouselColumnTemplateBuilder(
            $namaMasjid[$i],
            $alamatMasjid[$i],
            $urlPhotoMasjidTerdekat[$i], [
            new UriTemplateActionBuilder('Detail Rute', $urlDirection[$i]),
          ]);
          array_push($columnTemplateBuilders, $columnTemplateBuilder);

          $i++;
        }
      }
      else{
        $this->bot->replyMessage($event['replyToken'], 'Tak bisa looping array');
      }

      // Carousel Template builder and send reply template message
      $carouselTemplateBuilder = new CarouselTemplateBuilder($columnTemplateBuilders);
      $templateMessage = new TemplateMessageBuilder('Gunakan mobile app untuk melihat pesan', $carouselTemplateBuilder);
      $this->bot->replyMessage($event['replyToken'], $templateMessage);

    }

  }
  private function textMessage($event)
  {
    $userMessage = $event['message']['text'];
      if(strtolower($userMessage) == 'masjid terdekat')
      {
        $message = 'Silahkan share lokasi kamu ya dengan fitur share location (tombol +, dan pilih location dan klik share location)';
        $textMessageBuilder = new TextMessageBuilder($message);
        $this->bot->replyMessage($event['replyToken'], $textMessageBuilder);
      }
      elseif(strtolower($userMessage) == 'one click one ayat'){
        $this->oneClickOneAyat($event['replyToken'], $userMessage);
      }
      elseif(strtolower($userMessage) == 'jadwal sholat'){
        $replyMessage = 'Ok, tolong share lokasi kamu dulu yah! Supaya waktu shalat yang aku infokan sesuai dengan zona waktu di tempat kamu ';
        $this->jadwalShalat($event['replyToken'], $replyMessage);
      }
      else{
        //$this->stickerMessage($event['replyToken'], $userMessage);
          //$this->oneClickOneAyat($event['replyToken'], $userMessage);
        $this->bot->replyMessage($event['replyToken'], 'in else statement');
      }
/*      else {
        $message = 'Under Development...';
        $textMessageBuilder = new TextMessageBuilder($message);
        $this->bot->replyMessage($event['replyToken'], $textMessageBuilder);
      }*/
  }

  private function stickerMessage($replyToken, $message)
  {
    // create sticker message
    $stickerMessageBuilder = new StickerMessageBuilder(1, 106);

    // create text message
    $message = 'Send From function stickerMessage.';
    $textMessageBuilder = new TextMessageBuilder($message);

    // merge all message
    $multiMessageBuilder = new MultiMessageBuilder();
    $multiMessageBuilder->add($stickerMessageBuilder);
    $multiMessageBuilder->add($textMessageBuilder);
    // send message
    $this->bot->replyMessage($replyToken, $multiMessageBuilder);
  }

  private function oneClickOneAyat($replyToken, $message){
    $getAyat = $this->getRandomAyatBySurah();
    $arabicAyat = "https://api.alquran.cloud/ayah/".$getAyat;
    $translationAyat = "https://api.alquran.cloud/ayah/".$getAyat."/id.indonesian";

    // get url arabic ayat and Decode $returnedAyat
    $returnedAyat = $this->get_data($arabicAyat);
    $resultAyat = json_decode($returnedAyat,true);

    $surahNumber = $resultAyat['data']['surah']['number'];
    $surahName = $resultAyat['data']['surah']['name'];
    $surahEnglishName = $resultAyat['data']['surah']['englishName'];
    $numberInSurah = $resultAyat['data']['numberInSurah'];

    // get url translation ayat and Decode $translationAyat
    $returnedTranslationAyat = $this->get_data($translationAyat);
    $resultTranslation = json_decode($returnedTranslationAyat,true);
    $translationText = '"'.$resultTranslation['data']['text'].'"';

    $message = "( ".$surahName." [".$surahNumber."]" . " " . $numberInSurah . " ) : \n\n";
    $message .= $resultAyat['data']['text']."\n\n";
    $message .= "( Surah ".$surahEnglishName. " [".$surahNumber."]" ." ".$numberInSurah." ) : \n";
    $message .= $translationText;
    $textMessageBuilder = new TextMessageBuilder($message);
    $this->bot->replyMessage($replyToken, $textMessageBuilder);
/*
    $imageUrl = "https://cdn.alquran.cloud/media/image/2/255";
    $buttonTemplateBuilder = new ButtonTemplateBuilder(
      'My button sample',
      'Hello my button',
      $imageUrl,
      [
        new UriTemplateActionBuilder('Go to line.me', 'https://line.me'),
        new PostbackTemplateActionBuilder('Buy', 'action=buy&itemid=123'),
        new PostbackTemplateActionBuilder('Add to cart', 'action=add&itemid=123'),
        new MessageTemplateActionBuilder('Say message', 'hello hello'),
      ]
    );
    $templateMessage = new TemplateMessageBuilder('Button alt text', $buttonTemplateBuilder);
    $this->bot->replyMessage($replyToken, $templateMessage);*/
  }

  // Function to randomAyat
  private function getRandomAyatBySurah()
  {
    $randomSurah = rand(1,114);
    $detailSurah = $this->webhook_m->getSurahQuran($randomSurah);
    $randomAyat = rand(1,$detailSurah['count_ayat']);

    // return format [surah:ayat], example : 2:255
    return $detailSurah['surah_number'].":".$randomAyat;
  }

  private function jadwalShalat($replyToken, $message){
    $textMessageBuilder = new TextMessageBuilder($message);
    $this->bot->replyMessage($replyToken, $textMessageBuilder);
  }
}