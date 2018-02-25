<?php
  const BAD_REQUEST = 400;

  function get($data, $dottedPath, $default=Null) {
    $parts = explode('.', $dottedPath);
    $val = $data;
    foreach ($parts as $segment) {
      if (!isset($val[$segment])) {
        return $default;
      }
      $val = $val[$segment];
    }
    return $val;
  }

  //
  if (!function_exists("validation_failure")) {
    function validation_failure($message) {
      error_log($message);
      http_response_code(BAD_REQUEST);
      exit();
    }
  }
  //
  // Validate keychainUri is proper (from Amazon)
  //
  function validateKeychainUri($keychainUri) {

    $uriParts = parse_url($keychainUri);

    if (strcasecmp($uriParts['host'], 's3.amazonaws.com') != 0)
      validation_failure('The host for the Certificate provided in the header is invalid');

    if (strpos($uriParts['path'], '/echo.api/') !== 0)
      validation_failure('The URL path for the Certificate provided in the header is invalid');

    if (strcasecmp($uriParts['scheme'], 'https') != 0)
      validation_failure('The URL is using an unsupported scheme. Should be https');

    if (array_key_exists('port', $uriParts) && $uriParts['port'] != '443')
      validation_failure('The URL is using an unsupported https port');

  }


  function parseAndValidateRequest($applicationIdValidation, $userIdValidation, $cacheDir='/var/cert-cache', $postBody=False) {
    // $applicationIdValidation = 'amzn1.echo-sdk-ams.app.GUID####';
    // $userIdValidation = 'amzn1.account.GUID###';
    $echoServiceDomain = 'echo-api.amazon.com';

    // Capture Amazon's POST JSON request:
    if ($postBody === False) {
      $jsonRequestSrc = file_get_contents('php://input');
    } else {
      $jsonRequestSrc = $postBody;
    }
    $data = json_decode($jsonRequestSrc, true);

    //
    // Parse out key variables
    //
    $sessionId = @$data['session']['sessionId'];
    $applicationId = @$data['session']['application']['applicationId'];
    $userId = @$data['session']['user']['userId'];
    $requestTimestamp = @$data['request']['timestamp'];
    $requestType = $data['request']['type'];

    // Die if applicationId isn't valid
    if ($applicationId != $applicationIdValidation) validation_failure('Invalid Application id: ' . $applicationId);

    // Die if this request isn't coming from Matt Farley's Amazon Account
    if ($userId != $userIdValidation) validation_failure('Invalid User id: ' . $userId);

    // Determine if we need to download a new Signature Certificate Chain from Amazon
    $md5pem = $cacheDir . DIRECTORY_SEPARATOR . md5($_SERVER['HTTP_SIGNATURECERTCHAINURL']);
    $md5pem = $md5pem . '.pem';

    // If we haven't received a certificate with this URL before, store it as a cached copy
    if (!file_exists($md5pem)) {
      file_put_contents($md5pem, file_get_contents($_SERVER['HTTP_SIGNATURECERTCHAINURL']));
    }

    // Validate proper format of Amazon provided certificate chain url
    validateKeychainUri($_SERVER['HTTP_SIGNATURECERTCHAINURL']);

    // Validate certificate chain and signature
    $pem = file_get_contents($md5pem);
    $ssl_check = openssl_verify($jsonRequestSrc, base64_decode($_SERVER['HTTP_SIGNATURE']), $pem);
    if ($ssl_check != 1)
      validation_failure(openssl_error_string());

    // Parse certificate for validations below
    $parsedCertificate = openssl_x509_parse($pem);
    if (!$parsedCertificate)
      validation_failure('x509 parsing failed');

    // Check that the domain echo-api.amazon.com is present in the Subject Alternative Names (SANs) section of the signing certificate
    if (strpos($parsedCertificate['extensions']['subjectAltName'], $echoServiceDomain) === false)
      validation_failure('subjectAltName Check Failed');

    // Check that the signing certificate has not expired (examine both the Not Before and Not After dates)
    $validFrom = $parsedCertificate['validFrom_time_t'];
    $validTo = $parsedCertificate['validTo_time_t'];
    $time = time();
    if (!($validFrom <= $time && $time <= $validTo))
      validation_failure('certificate expiration check failed');

    // Check the timestamp of the request and ensure it was within the past minute
    if (time() - strtotime($requestTimestamp) > 150)
      validation_failure('timestamp validation failure.. Current time: ' . time() . ' vs. Timestamp: ' . $requestTimestamp);
    return $data;
  }