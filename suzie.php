<?php
  $DEBUG_MODE = 0;
  $CACHE_DIR = 'cache';
  $APP_INFO = json_decode(file_get_contents("alexa_info.json"), True);
  $CLIPS_BASE_URL = "https://${_SERVER['HTTP_HOST']}" . dirname($_SERVER['REQUEST_URI']);
  $DEFAULT_CLIP =  "TheMoonLanding.mp3";

  function validation_failure($message) {
    file_put_contents($message , FILE_APPEND);
    error_log($message);
    http_response_code(BAD_REQUEST);
    exit();
  }

  require_once('alexa_parser.php');

  if ($DEBUG_MODE) {
    ini_set('display_errors', 1);
    ob_start();
    phpinfo(INFO_VARIABLES + INFO_ENVIRONMENT);
    $debug_body = file_get_contents('php://input');
    $input = json_decode($debug_body, TRUE);
    file_put_contents('out.html', ob_get_clean() . "\n<pre>" . json_encode($input, JSON_PRETTY_PRINT) . "</pre>");
  }

  $data = parseAndValidateRequest($APP_INFO["appId"], $APP_INFO["userId"], "cache", isset($debug_body) ? $debug_body : False );

  // extract from JSON
  $intent = get($data,'request.intent.name', False);
  $slots = get($data,'request.intent.slots', False);

  header('Content-Type: application/json;charset=UTF-8');

  $clip = $CLIPS_BASE_URL . DIRECTORY_SEPARATOR . $DEFAULT_CLIP;

  $directives = array();

  if ($intent === "AMAZON.PlayIntent" || $intent === "play") {
    $dir = array(
      "type" => "AudioPlayer.Play",
      "playBehavior" => "REPLACE_ALL",
      "audioItem" => array(
        "stream" => array(
          "token" => "test-123",
          "url" => $clip,
          "offsetInMilliseconds" => 0
        )
      )
    );
    $directives[] = $dir;
  }

  if ($intent === "AMAZON.ResumeIntent") {
    $dir = array(
      "type" => "AudioPlayer.Play",
      "playBehavior" => "REPLACE_ALL",
      "audioItem" => array(
        "stream" => array(
          "token" => "test-123",
          "url" => $clip,
          "offsetInMilliseconds" => get($data, 'context.AudioPlayer.offsetInMilliseconds', 0) * 1
        )
      )
    );
    $directives[] = $dir;
  }
  
  if ($intent === "AMAZON.StopIntent" || $intent === "AMAZON.PauseIntent") {
    $dir = array(
      "type" => "AudioPlayer.Stop"
    );
    $directives[] = $dir;
  }


	$doc = array(
    "version"=> "1.0",
    "response" => array(
      "outputSpeech" => array(
          "type" => "PlainText",
          "text" => "ok"
      ),
      "card" => array(
        "type" => "Simple",
        "title" => "Today's Clip",
        "content" => "Some nice piano music!" . ($DEBUG_MODE ? "\nintent=$intent slots=" . var_export($slots === false ? 'none' : $slots, true) : "")
      ),
      "shouldEndSession" => True,
      "directives" => $directives
    )
	);

	$json = json_encode($doc);
	print $json;
