<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requirePost();

try {
    $input = requestData();
    $name = trim((string)($input['name'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $serviceId = trim((string)($input['service'] ?? ''));
    $branch = trim((string)($input['branch'] ?? ''));
    $date = trim((string)($input['date'] ?? ''));
    $time = strtoupper(trim((string)($input['timeSlot'] ?? '')));
    $plate = strtoupper(trim((string)($input['plateNumber'] ?? '')));
    $make = trim((string)($input['vehicleMake'] ?? ''));
    $model = trim((string)($input['vehicleModel'] ?? ''));
    $year = trim((string)($input['vehicleYear'] ?? ''));
    $method = (string)($input['paymentMethod'] ?? '');
    if ($name === '' || strlen($name)>120 || !filter_var($email,FILTER_VALIDATE_EMAIL)
        || !preg_match('/^[A-Za-z0-9_-]{1,150}$/',$serviceId) || !in_array($branch,['Cabuyao Branch','Santa Rosa Branch'],true)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || !preg_match('/^\d{1,2}:\d{2} ?(AM|PM)$/',$time)
        || $plate === '' || $make === '' || $model === '' || !preg_match('/^\d{4}$/',$year)
        || !in_array($method,['e_wallet','cash'],true)) jsonResponse(['ok'=>false,'message'=>'Please provide valid booking details.'],422);
    $timezone = new DateTimeZone('Asia/Manila');
    $slot = DateTimeImmutable::createFromFormat('!Y-m-d g:i A',$date.' '.preg_replace('/^0(?=\d:)/','',$time),$timezone);
    if (!$slot || $slot <= new DateTimeImmutable('now',$timezone)) jsonResponse(['ok'=>false,'message'=>'Choose a future appointment slot.'],422);
    $account = firebaseServiceAccount();
    $serviceDoc = 'https://firestore.googleapis.com/v1/projects/'.rawurlencode($account['project_id']).'/databases/(default)/documents/services/'.rawurlencode($serviceId);
    $ctx=stream_context_create(['http'=>['method'=>'GET','header'=>'Authorization: Bearer '.firebaseAdminAccessToken(),'timeout'=>15,'ignore_errors'=>true]]);
    $raw=@file_get_contents($serviceDoc,false,$ctx); $doc=is_string($raw)?json_decode($raw,true):null;
    $sf=$doc['fields']??[]; $serviceName=(string)($sf['name']['stringValue']??''); $price=(float)($sf['price']['doubleValue']??$sf['price']['integerValue']??0); $state=strtolower((string)($sf['status']['stringValue']??''));
    if ($serviceName==='' || !in_array($state,['active','available'],true) || $price<=0) jsonResponse(['ok'=>false,'message'=>'This service is unavailable or has no configured price.'],422);
    $bookingId='walkin_'.bin2hex(random_bytes(12)); $invoiceId='walkin_inv_'.bin2hex(random_bytes(12)); $now=gmdate('Y-m-d\\TH:i:s\\Z');
    $branchId=$branch==='Cabuyao Branch'?'cabuyao':'santa_rosa';
    $reference='OTC-'.(new DateTimeImmutable('now',$timezone))->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(2)));
    firestorePatchDocument($account,'bookings',$bookingId,[
      'bookingId'=>['stringValue'=>$bookingId],'customerId'=>['stringValue'=>''],'customerName'=>['stringValue'=>$name],'customerEmail'=>['stringValue'=>$email],
      'serviceId'=>['stringValue'=>$serviceId],'branchId'=>['stringValue'=>$branchId],'branch'=>['stringValue'=>$branch],
      'date'=>['stringValue'=>$date],'timeSlot'=>['stringValue'=>$time],'plateNumber'=>['stringValue'=>$plate],
      'vehicleMake'=>['stringValue'=>$make],'vehicleModel'=>['stringValue'=>$model],'vehicleYear'=>['integerValue'=>$year],
      'source'=>['stringValue'=>'walk_in_pos'],'is_guest'=>['booleanValue'=>true],'status'=>['stringValue'=>'pending'],
      'paymentStatus'=>['stringValue'=>$method==='cash'?'unpaid':'pending'],'paymentMethod'=>['stringValue'=>$method==='cash'?'cash_otc':'paymongo_checkout'],
      'payment_status'=>['stringValue'=>$method==='cash'?'unpaid':'pending'],'payment_method'=>['stringValue'=>$method==='cash'?'cash_otc':'paymongo_checkout'],
      'invoiceId'=>['stringValue'=>$invoiceId],'createdAt'=>['timestampValue'=>$now],'updatedAt'=>['timestampValue'=>$now],
    ]);
    firestorePatchDocument($account,'invoices',$invoiceId,[
      'invoiceId'=>['stringValue'=>$invoiceId],'bookingId'=>['stringValue'=>$bookingId],'customerId'=>['stringValue'=>''],
      'customerName'=>['stringValue'=>$name],'customerEmail'=>['stringValue'=>$email],'branchId'=>['stringValue'=>$branchId],
      'serviceId'=>['stringValue'=>$serviceId],'serviceName'=>['stringValue'=>$serviceName],'source'=>['stringValue'=>'walk_in_pos'],'is_guest'=>['booleanValue'=>true],
      'paymentMethod'=>['stringValue'=>$method==='cash'?'cash_otc':'paymongo_checkout'],'paymentStatus'=>['stringValue'=>$method==='cash'?'unpaid':'pending'],
      'payment_method'=>['stringValue'=>$method==='cash'?'cash_otc':'paymongo_checkout'],'payment_status'=>['stringValue'=>$method==='cash'?'unpaid':'pending'],
      'referenceNumber'=>['stringValue'=>$reference],'servicesAdded'=>['arrayValue'=>['values'=>[['stringValue'=>$serviceId]]]],
      'serviceDetails'=>['arrayValue'=>['values'=>[['mapValue'=>['fields'=>['id'=>['stringValue'=>$serviceId],'name'=>['stringValue'=>$serviceName],'price'=>['doubleValue'=>$price],'quantity'=>['integerValue'=>'1'],'lineTotal'=>['doubleValue'=>$price]]]]]]],
      'runningTotal'=>['doubleValue'=>$price],'finalAmount'=>['doubleValue'=>$price],'totalAmount'=>['doubleValue'=>$price],
      'downpaymentAmount'=>['doubleValue'=>0],'serviceStatus'=>['stringValue'=>'pending'],'status'=>['stringValue'=>$method==='cash'?'unpaid':'pending'],
      'createdAt'=>['timestampValue'=>$now],'updatedAt'=>['timestampValue'=>$now],
    ]);
    jsonResponse(['ok'=>true,'bookingId'=>$bookingId,'invoiceId'=>$invoiceId,'amount'=>$price,'referenceNumber'=>$reference,'customerName'=>$name,'branch'=>$branch,'serviceName'=>$serviceName]);
} catch(Throwable $error) { error_log('Walk-in booking creation failed: '.$error->getMessage()); jsonResponse(['ok'=>false,'message'=>'Unable to save this booking. Please retry shortly.'],500); }
