<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requirePost();

function walkinInput(array $input, string ...$keys): string
{
    foreach ($keys as $key) {
        if (isset($input[$key]) && !is_array($input[$key])) return trim((string)$input[$key]);
    }
    return '';
}

function walkinFirestoreFields(array $account, string $collection, string $id): ?array
{
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($account['project_id'])
        . '/databases/(default)/documents/' . rawurlencode($collection) . '/' . rawurlencode($id);
    $response = secureHttpRequest($url, 'GET', ['Authorization: Bearer ' . firebaseAdminAccessToken()], null, 15);
    if ($response['status'] === 404) return null;
    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException('Firestore service lookup failed (HTTP ' . $response['status'] . ').');
    }
    $document = json_decode($response['body'], true);
    if (!is_array($document)) throw new RuntimeException('Firestore service lookup returned invalid JSON.');
    $fields = $document['fields'] ?? null;
    if (!is_array($fields)) return null;
    $values = [];
    foreach ($fields as $key=>$value) {
        foreach (['stringValue','integerValue','doubleValue','booleanValue'] as $type) {
            if (array_key_exists($type,$value)) { $values[$key]=$value[$type]; break; }
        }
    }
    return $values;
}

try {
    $input = requestData();
    $name = walkinInput($input, 'name', 'customerName', 'customer_name');
    $email = strtolower(walkinInput($input, 'email', 'customerEmail', 'customer_email'));
    $serviceKey = walkinInput($input, 'service', 'serviceId', 'service_id', 'serviceName', 'service_name');
    $branch = walkinInput($input, 'branch', 'branchId', 'branch_id');
    $date = walkinInput($input, 'date', 'appointmentDate', 'appointment_date');
    $time = strtoupper(walkinInput($input, 'timeSlot', 'time_slot', 'time'));
    $plate = strtoupper(walkinInput($input, 'plateNumber', 'plate_number', 'plateNo', 'plate_no'));
    $make = walkinInput($input, 'vehicleMake', 'vehicle_make', 'make');
    $model = walkinInput($input, 'vehicleModel', 'vehicle_model', 'model');
    $year = walkinInput($input, 'vehicleYear', 'vehicle_year', 'year');
    $phone = walkinInput($input, 'phone', 'contact', 'contactNumber', 'contact_number');

    $invalid = [];
    if ($name === '' || strlen($name)>120) $invalid[]='name';
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) $invalid[]='email';
    if ($serviceKey === '') $invalid[]='service';
    $branchId = normalizeBranchId($branch);
    $branchLabel = normalizeBranchLabel($branch);
    if ($branchId === '') $invalid[]='branch';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) $invalid[]='date';
    if (!preg_match('/^\d{1,2}:\d{2}\s?(AM|PM)\s*-\s*\d{1,2}:\d{2}\s?(AM|PM)$/i',$time)) $invalid[]='timeSlot';
    if ($plate === '') $invalid[]='plateNumber';
    if ($make === '') $invalid[]='vehicleMake';
    if ($model === '') $invalid[]='vehicleModel';
    if (!preg_match('/^\d{4}$/',$year) || (int)$year<1950 || (int)$year>2100) $invalid[]='vehicleYear';
    if ($phone !== '' && !preg_match('/^[+0-9() .-]{7,25}$/',$phone)) $invalid[]='phone';
    if ($invalid) jsonResponse(['ok'=>false,'message'=>'Invalid or missing field(s): '.implode(', ',$invalid).'.'],422);

    $timezone = new DateTimeZone('Asia/Manila');
    $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d',$date,$timezone);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) jsonResponse(['ok'=>false,'message'=>'Invalid field: date must be a real calendar date.'],422);
    preg_match('/^(\d{1,2}:\d{2})\s*(AM|PM)\s*-\s*(\d{1,2}:\d{2})\s*(AM|PM)$/i',$time,$timeParts);
    $start = DateTimeImmutable::createFromFormat('!Y-m-d g:i A',$date.' '.preg_replace('/^0(?=\d:)/','',$timeParts[1]).' '.strtoupper($timeParts[2]),$timezone);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d g:i A',$date.' '.preg_replace('/^0(?=\d:)/','',$timeParts[3]).' '.strtoupper($timeParts[4]),$timezone);
    if (!$start || !$end || $end <= $start || $start <= new DateTimeImmutable('now',$timezone)) {
        jsonResponse(['ok'=>false,'message'=>'Invalid field: timeSlot must be a future, valid appointment range.'],422);
    }

    $account = firebaseServiceAccount();
    $service = null;
    if (preg_match('/^[A-Za-z0-9_-]{1,150}$/',$serviceKey)) $service = walkinFirestoreFields($account,'services',$serviceKey);
    if (!$service) {
        // Accept a service name as well as a Firestore document ID.
        $queryUrl = 'https://firestore.googleapis.com/v1/projects/'.rawurlencode($account['project_id']).'/databases/(default)/documents:runQuery';
        $query = ['structuredQuery'=>['from'=>[['collectionId'=>'services']],'where'=>['fieldFilter'=>['field'=>['fieldPath'=>'name'],'op'=>'EQUAL','value'=>['stringValue'=>$serviceKey]]],'limit'=>1]];
        $queryResponse = secureHttpRequest($queryUrl, 'POST', [
            'Authorization: Bearer ' . firebaseAdminAccessToken(), 'Content-Type: application/json',
        ], json_encode($query, JSON_THROW_ON_ERROR), 15);
        if ($queryResponse['status'] < 200 || $queryResponse['status'] >= 300) {
            throw new RuntimeException('Firestore service query failed (HTTP ' . $queryResponse['status'] . ').');
        }
        $rows = json_decode($queryResponse['body'], true);
        if (!is_array($rows)) throw new RuntimeException('Firestore service query returned invalid JSON.');
        $doc=$rows[0]['document']??null;
        if (is_array($doc)) {
            $service=[];
            foreach (($doc['fields']??[]) as $key=>$value) foreach (['stringValue','integerValue','doubleValue','booleanValue'] as $type) if (array_key_exists($type,$value)) { $service[$key]=$value[$type]; break; }
            if (preg_match('~/services/([^/]+)$~',(string)($doc['name']??''),$m)) $service['_id']=$m[1];
        }
    }
    $serviceName=(string)($service['name']??$input['serviceName']??$serviceKey);
    $serviceId=(string)($service['_id']??$serviceKey);
    if (!$service) jsonResponse(['ok'=>false,'message'=>'Selected service was not found. Refresh the service list and try again.'],404);
    if (isset($service['status']) && !in_array(strtolower((string)$service['status']),['active','available'],true)) {
        jsonResponse(['ok'=>false,'message'=>'Invalid field: service is currently unavailable.'],422);
    }
    $price=(float)($service['price']??0);
    if (!is_finite($price) || $price<=0) jsonResponse(['ok'=>false,'message'=>'Invalid field: service price is not configured.'],422);

    $branch = $branchLabel;
    $methodName='OTC / Walk-In';
    $paymentStatus='unpaid';
    $bookingId='walkin_'.bin2hex(random_bytes(12)); $invoiceId='walkin_inv_'.bin2hex(random_bytes(12));
    $now=gmdate('Y-m-d\\TH:i:s\\Z');
    // config.php supports OTC and PMO reference sources; OTC is the walk-in counter prefix.
    $reference=cooltimeReferenceNumber('OTC',$branchLabel,$date);
    $bookingFields=[
      'bookingId'=>['stringValue'=>$bookingId],'customerId'=>['stringValue'=>''],'customerName'=>['stringValue'=>$name],'customerEmail'=>['stringValue'=>$email],
      'serviceId'=>['stringValue'=>$serviceId],'serviceName'=>['stringValue'=>$serviceName],'branchId'=>['stringValue'=>$branchId],'branch'=>['stringValue'=>$branchLabel],
      'date'=>['stringValue'=>$date],'timeSlot'=>['stringValue'=>$time],'plateNumber'=>['stringValue'=>$plate],
      'vehiclePlate'=>['stringValue'=>$plate],'vehicle_plate'=>['stringValue'=>$plate],
      'vehicleMake'=>['stringValue'=>$make],'vehicleModel'=>['stringValue'=>$model],'vehicleYear'=>['integerValue'=>$year],
      'plate_number'=>['stringValue'=>$plate],'vehicle_make'=>['stringValue'=>$make],'vehicle_model'=>['stringValue'=>$model],'vehicle_year'=>['integerValue'=>$year],
      'vehicle'=>['mapValue'=>['fields'=>[
        'plateNumber'=>['stringValue'=>$plate],'plate'=>['stringValue'=>$plate],'make'=>['stringValue'=>$make],
        'model'=>['stringValue'=>$model],'year'=>['integerValue'=>$year],
      ]]],
      'source'=>['stringValue'=>'walk_in_pos'],'bookingType'=>['stringValue'=>'walk_in'],'is_guest'=>['booleanValue'=>true],'status'=>['stringValue'=>'confirmed'],
      'paymentStatus'=>['stringValue'=>$paymentStatus],'paymentMethod'=>['stringValue'=>$methodName],
      'paymentBalance'=>['doubleValue'=>$price],
      'payment_status'=>['stringValue'=>$paymentStatus],'payment_method'=>['stringValue'=>$methodName],
      'referenceNumber'=>['stringValue'=>$reference],'invoiceReference'=>['stringValue'=>$reference],
      'walkInReferenceId'=>['stringValue'=>$reference],'invoiceNumber'=>['stringValue'=>$reference],
      'invoiceId'=>['stringValue'=>$invoiceId],'createdAt'=>['timestampValue'=>$now],'updatedAt'=>['timestampValue'=>$now],
    ];
    if ($phone!=='') $bookingFields['phone']=['stringValue'=>$phone];
    firestorePatchDocument($account,'bookings',$bookingId,$bookingFields);
    firestorePatchDocument($account,'invoices',$invoiceId,[
      'invoiceId'=>['stringValue'=>$invoiceId],'bookingId'=>['stringValue'=>$bookingId],'customerId'=>['stringValue'=>''],
      'customerName'=>['stringValue'=>$name],'customerEmail'=>['stringValue'=>$email],'branchId'=>['stringValue'=>$branchId],'branch'=>['stringValue'=>$branchLabel],
      'serviceId'=>['stringValue'=>$serviceId],'serviceName'=>['stringValue'=>$serviceName],'source'=>['stringValue'=>'walk_in_pos'],'is_guest'=>['booleanValue'=>true],
      'paymentMethod'=>['stringValue'=>$methodName],'paymentStatus'=>['stringValue'=>$paymentStatus],
      'payment_method'=>['stringValue'=>$methodName],'payment_status'=>['stringValue'=>$paymentStatus],
      'bookingType'=>['stringValue'=>'walk_in'],'referenceNumber'=>['stringValue'=>$reference],
      'invoiceReference'=>['stringValue'=>$reference],'walkInReferenceId'=>['stringValue'=>$reference],
      'invoiceNumber'=>['stringValue'=>$reference],'amount'=>['doubleValue'=>$price],
      'servicesAdded'=>['arrayValue'=>['values'=>[['stringValue'=>$serviceId]]]],
      'serviceDetails'=>['arrayValue'=>['values'=>[['mapValue'=>['fields'=>['id'=>['stringValue'=>$serviceId],'name'=>['stringValue'=>$serviceName],'price'=>['doubleValue'=>$price],'quantity'=>['integerValue'=>'1'],'lineTotal'=>['doubleValue'=>$price]]]]]]],
      'runningTotal'=>['doubleValue'=>$price],'finalAmount'=>['doubleValue'=>$price],'totalAmount'=>['doubleValue'=>$price],
      'downpaymentAmount'=>['doubleValue'=>0],'balanceDue'=>['doubleValue'=>$price],'balance_due'=>['doubleValue'=>$price],
      'paymentBalance'=>['doubleValue'=>$price],'serviceStatus'=>['stringValue'=>'pending'],'status'=>['stringValue'=>'unpaid'],
      'createdAt'=>['timestampValue'=>$now],'updatedAt'=>['timestampValue'=>$now],
    ]);

    // Side effects happen only after both Firestore records have been saved.
    try {
        invalidateSlotAvailabilityCache($branchId, $date);
    } catch (Throwable $cacheError) {
        error_log(sprintf('Walk-in booking saved, but slot cache invalidation failed at %s:%d: %s',
            $cacheError->getFile(), $cacheError->getLine(), $cacheError->getMessage()));
    }
    try {
        $confirmationText = 'Your CoolTime walk-in booking is confirmed. Reference: ' . $reference
            . '. Please proceed to the service bay. Payment of PHP ' . number_format($price, 2)
            . ' is due after your service is completed.';
        trySendConfiguredEmail($email, 'CoolTime walk-in booking confirmed — ' . $reference,
            nl2br(htmlspecialchars($confirmationText, ENT_QUOTES, 'UTF-8')), $confirmationText);
    } catch (Throwable $emailError) {
        error_log(sprintf('Walk-in booking saved, but confirmation email failed at %s:%d: %s',
            $emailError->getFile(), $emailError->getLine(), $emailError->getMessage()));
    }
    jsonResponse(['ok'=>true,'bookingId'=>$bookingId,'invoiceId'=>$invoiceId,'amount'=>$price,
        'referenceNumber'=>$reference,'invoiceReference'=>$reference,'walkInReferenceId'=>$reference,
        'customerName'=>$name,'branch'=>$branchLabel,'serviceName'=>$serviceName]);
} catch(Throwable $error) {
    error_log(sprintf('Walk-in booking creation failed at %s:%d: %s', $error->getFile(), $error->getLine(), $error->getMessage()));
    jsonResponse(['ok'=>false,'message'=>'Unable to save this booking. Please retry shortly.'],500);
}
