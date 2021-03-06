<?php
defined('BASEPATH') OR exit('No direct script access allowed');

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

class Webhook extends CI_Controller
{
  private $bot;
  private $events;
  private $signature;
  private $user;

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
    if ($res->isSucceeded()) {
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
  private function getData($url)
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

  private function locationMessage($event)
  {
      // Cek jika user mengirimkan event text dengan type location
      $userLocation = $event['message']['type'];
      if($userLocation == 'location') {
        /* Cek jika user mengirimkan event text dengan type location setelah sebelumnya
        *  mengklik tombol masjid terdekat, jika ya bot akan krimkan lokasi masjid terdekat jika
        *  tidak  bot akan mengirimkan jadwal shalat sesuai timezone dari lokasi yang dikirimkan
        */
        $lastEventUser = $this->getBeforeLastEvent($event['source']['userId']);
        if(strtolower($lastEventUser) == 'masjid terdekat'){
          $locationFromUserShared = $event['message']['latitude'] . "," . $event['message']['longitude'];

          $urlMasjidTerdekat ="https://maps.googleapis.com/maps/api/place/nearbysearch/json?";
          $urlMasjidTerdekat .="location=". $event['message']['latitude'] . "," . $event['message']['longitude'];
          $urlMasjidTerdekat .="&radius=500&type=mosque&keyword=masjid";
          $urlMasjidTerdekat .="&key=".$_ENV['GMAPS_API_KEY'];

          // get url maps to parse json
          $returned_content = $this->getData($urlMasjidTerdekat);
          // Decode google maps json
          $result = json_decode($returned_content,true);

          $columnTemplateBuilders = array();
          if(is_array($result['results'])) {
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
          // Carousel Template builder and send reply template message
          $carouselTemplateBuilder = new CarouselTemplateBuilder($columnTemplateBuilders);
          $templateMessage = new TemplateMessageBuilder('Gunakan mobile app untuk melihat pesan', $carouselTemplateBuilder);
          $this->bot->replyMessage($event['replyToken'], $templateMessage);
        }
        elseif(strtolower($lastEventUser) == 'jadwal shalat'){
          $this->jadwalShalat($event);
        }
        else{
          exit();
        }
      }
  }

  /**
   * Function to reply message from different action click
   * @param $event
   */
  private function textMessage($event)
  {
    $userMessage = $event['message']['text'];

    switch (strtolower($userMessage)){
      case 'masjid terdekat':
        $message = 'Silahkan share lokasi kamu ya dengan fitur share location (tombol +, dan pilih location dan klik share location)';
        $textMessageBuilder = new TextMessageBuilder($message);
        $this->bot->replyMessage($event['replyToken'], $textMessageBuilder);
        break;
      case 'one click one ayat':
        $this->oneClickOneAyat($event['replyToken']);
        break;
      case 'jadwal shalat':
        $textMessageBuilder = new TextMessageBuilder('Share Lokasi kamu dulu ya supaya aku sesuaikan dengan zona waktu di tempat kamu');
        $this->bot->replyMessage($event['replyToken'], $textMessageBuilder);
        break;
      case 'feedback':
        $feedBack =  new TextMessageBuilder("kirim email ke arsan.irianto@gmail.com ya, untuk mengirim feedback kamu");
        $this->bot->replyMessage($event['replyToken'], $feedBack);
        break;
    }
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

  private function oneClickOneAyat($replyToken)
  {
      $getAyat = $this->getRandomAyatBySurah();
      $arabicAyat = "https://api.alquran.cloud/ayah/".$getAyat;
      $translationAyat = "https://api.alquran.cloud/ayah/".$getAyat."/id.indonesian";

      // get url arabic ayat and Decode $returnedAyat
      $returnedAyat = $this->getData($arabicAyat);
      $resultAyat = json_decode($returnedAyat,true);

      $surahNumber = $resultAyat['data']['surah']['number'];
      $surahName = $resultAyat['data']['surah']['name'];
      $surahEnglishName = $resultAyat['data']['surah']['englishName'];
      $numberInSurah = $resultAyat['data']['numberInSurah'];

      // get url translation ayat and Decode $translationAyat
      $returnedTranslationAyat = $this->getData($translationAyat);
      $resultTranslation = json_decode($returnedTranslationAyat,true);
      $translationText = '"'.$resultTranslation['data']['text'].'"';

      $message = "( ".$surahName." [".$surahNumber."]" . " " . $numberInSurah . " ) : \n\n";
      $message .= $resultAyat['data']['text']."\n\n";
      $message .= "( Surah ".$surahEnglishName. " [".$surahNumber."]" ." ".$numberInSurah." ) : \n";
      $message .= $translationText;
      $textMessageBuilder = new TextMessageBuilder($message);
      $this->bot->replyMessage($replyToken, $textMessageBuilder);
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

  /**
   * function Get Prayer Time by lat long, timezone after user share location
   * @param $event
   */
  private function jadwalShalat($event)
  {
    $latitudeFromUser = $event['message']['latitude'];
    $longitudeFromUser = $event['message']['longitude'];

    $timeStampToday = getdate();
    $indoDay = $this->getTranslatedDay($timeStampToday['weekday']);
    $indoMonth = $this->getTranslatedMonth($timeStampToday['month']);
    $timeZoneString = $this->getTimeZoneByLatLong($timeStampToday[0], $latitudeFromUser, $longitudeFromUser);

    //$timeStamp = date_timestamp_get(date("m/d/y"));
    $prayerTimeUrl = "http://api.aladhan.com/timings/".$timeStampToday."?latitude=".$latitudeFromUser."&longitude=".$longitudeFromUser."&timezonestring=".$timeZoneString."&method=3";
    // get url prayer time api to parse json
    $returned_content = $this->getData($prayerTimeUrl);
    // Decode google maps json
    $result = json_decode($returned_content,true);

    // Parse result
    $waktuShalat = $result['data']['timings'];
    $messageJadwalShalat = "Jadwal Shalat hari ini, ";
    $messageJadwalShalat.= $indoDay." ";
    $messageJadwalShalat.= $timeStampToday['mday']." ".$indoMonth." ".$timeStampToday['year']."\n\n";
    $messageJadwalShalat.= "Subuh     : ". $waktuShalat['Fajr']."\n";
    $messageJadwalShalat.= "Dzuhur    : ". $waktuShalat['Dhuhr']."\n";
    $messageJadwalShalat.= "Ashar       : ". $waktuShalat['Asr']."\n";
    $messageJadwalShalat.= "Maghrib  : ". $waktuShalat['Maghrib']."\n";
    $messageJadwalShalat.= "Isya          : ". $waktuShalat['Isha'];

    $messageInfo = "Jangan lupa shalat tepat waktu dan berjamaah di masjid ya. Nabi kita Shallallahu ‘alaihi wa sallam bersabda : \n\n";
    $messageHadist = '"'."Barangsiapa yang shalat karena Allah selama 40 hari secara berjama’ah dengan mendapatkan Takbir pertama (takbiratul ihramnya imam), maka ditulis untuknya dua kebebasan, yaitu kebebasan dari api neraka dan kebebasan dari sifat kemunafikan.(HR.Tirmidzi)".'"';

    //send message to reply message
    $textMessage1 =  new TextMessageBuilder($messageJadwalShalat);
    $textMessage2 =  new TextMessageBuilder($messageInfo. $messageHadist);

    $multiMessageBuilder = new MultiMessageBuilder();
    $multiMessageBuilder->add($textMessage1);
    $multiMessageBuilder->add($textMessage2);
    $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
  }

  private function getBeforeLastEvent($user_id)
  {
    $lastEvents = json_decode($this->webhook_m->getBeforeLastEventText($user_id), true);
    return $lastEvents['events'][0]['message']['text'];
  }

  private function getTimeZoneByLatLong($timeStamp, $latitude, $longitude)
  {
    $timeZoneUrl = "https://maps.googleapis.com/maps/api/timezone/json?location=".$latitude.",".$longitude."&timestamp=".$timeStamp."&key=".$_ENV['GMAPS_API_KEY_TIMEZONE'];

    // get url timezone Decode $timeZoneUrl
    $returnedTimeZone = $this->getData($timeZoneUrl);
    $resultTimeZone = json_decode($returnedTimeZone,true);

    return $resultTimeZone['timeZoneId'];
  }

  private function getTranslatedDay($dayEnglishName)
  {
    switch ($dayEnglishName){
      case "Sunday": return "Ahad";break;
      case "Monday": return "Senin";break;
      case "Tuesday": return "Selasa";break;
      case "Wednesday": return "Rabu";break;
      case "Thursday": return "Kamis";break;
      case "Friday": return "Jumat";break;
      case "Saturday": return "Sabtu";break;
    }
  }

  private function getTranslatedMonth($monthEnglishName)
  {
    switch ($monthEnglishName){
      case "January": return "Januari";break;
      case "February": return "Februari";break;
      case "March": return "Maret";break;
      case "April": return "April";break;
      case "May": return "Mei";break;
      case "June": return "Juni";break;
      case "July": return "Juli";break;
      case "August": return "Agustus";break;
      case "September": return "September";break;
      case "October": return "Oktober";break;
      case "November": return "November";break;
      case "December": return "Desember";break;
    }
  }

}