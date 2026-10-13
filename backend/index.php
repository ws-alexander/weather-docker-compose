<?php
declare(strict_types=1);
// Погода и климат. MySQL необязательна; без неё приложение продолжает работать.
header('X-Content-Type-Options: nosniff');
// Совместимость с хостингами, где расширение mbstring не включено.
if (!function_exists('mb_strtolower')) {
  function mb_strtolower(string $value, ?string $encoding=null): string {
    $upper='АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ';
    $lower='абвгдеёжзийклмнопрстуфхцчшщъыьэюя';
    return strtolower(strtr($value, array_combine(preg_split('//u',$upper,-1,PREG_SPLIT_NO_EMPTY),preg_split('//u',$lower,-1,PREG_SPLIT_NO_EMPTY))));
  }
}
if (!function_exists('mb_strlen')) {
  function mb_strlen(string $value, ?string $encoding=null): int { preg_match_all('/./us',$value,$m); return count($m[0]); }
}
if (!function_exists('mb_stripos')) {
  function mb_stripos(string $haystack, string $needle, int $offset=0, ?string $encoding=null): int|false {
    $pos=strpos(mb_strtolower($haystack,$encoding),mb_strtolower($needle,$encoding),$offset); return $pos===false?false:mb_strlen(substr(mb_strtolower($haystack,$encoding),0,$pos),$encoding);
  }
}
$pdo = null; $dbError = null;
$dbHost = getenv('DB_HOST') ?: 'db'; $dbName = getenv('DB_NAME') ?: 'weather_climate';
$dbUser = getenv('DB_USER') ?: 'weather_app'; $dbPass = getenv('DB_PASS') ?: '';
try { $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
catch (Throwable $e) { $dbError = 'MySQL не подключена — история будет доступна только в текущем браузере.'; }
function httpJson(string $url): array {
  $warning = null;
  $ctx = stream_context_create(['http' => ['timeout' => 12, 'ignore_errors' => true, 'header' => "User-Agent: WeatherClimate/1.0\r\nAccept: application/json\r\n"]]);
  set_error_handler(static function(int $severity, string $message) use (&$warning): bool { $warning = $message; return true; });
  try { $raw = file_get_contents($url, false, $ctx); } finally { restore_error_handler(); }
  $status = 0;
  foreach (($http_response_header ?? []) as $header) { if (preg_match('~^HTTP/\\S+\\s+(\\d{3})~', $header, $m)) $status = (int)$m[1]; }
  $host = (string)(parse_url($url, PHP_URL_HOST) ?: 'unknown host');
  if ($raw === false) {
    $reason = $warning ? preg_replace('/\\s+/', ' ', $warning) : 'сервер не вернул тело ответа';
    throw new RuntimeException("Не удалось обратиться к {$host}. Диагностика: {$reason}. Проверьте DNS, SSL-сертификаты и исходящие HTTPS-запросы хостинга.");
  }
  $data = json_decode($raw, true);
  if ($status >= 400) {
    $apiMessage = is_array($data) ? (string)($data['reason'] ?? $data['message'] ?? '') : '';
    throw new RuntimeException("Сервис {$host} вернул HTTP {$status}" . ($apiMessage !== '' ? ": {$apiMessage}" : '') . '.');
  }
  if (!is_array($data)) throw new RuntimeException("Сервис {$host} вернул некорректный JSON: " . json_last_error_msg() . '.');
  if (isset($data['error']) && $data['error']) throw new RuntimeException("Ошибка API {$host}: " . (string)($data['reason'] ?? 'запрос отклонён'));
  return $data;
}
function reply(array $data,int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function weatherLabelFromText(string $description): array {
  $s = mb_strtolower(trim($description), 'UTF-8');
  if (preg_match('/гроза|thunder|lightning/', $s)) return [$description ?: 'Гроза', 'rain'];
  if (preg_match('/снег|snow|sleet|ледян/', $s)) return [$description ?: 'Снег', 'cloud'];
  if (preg_match('/дожд|морос|ливен|rain|drizzle|shower/', $s)) return [$description ?: 'Дождь', 'rain'];
  if (preg_match('/туман|дымка|fog|mist|haze/', $s)) return [$description ?: 'Туман', 'cloud'];
  if (preg_match('/ясно|солнечно|clear|sunny/', $s)) return [$description ?: 'Ясно', 'sun'];
  if (preg_match('/ветер|wind/', $s)) return [$description ?: 'Ветрено', 'cloud'];
  if (preg_match('/облач|пасмур|cloud|overcast/', $s)) return [$description ?: 'Облачно', 'cloud'];
  return [$description !== '' ? $description : 'Переменная облачность', 'cloud'];
}
function climateLabel(float $t): array { if($t<0)return ['Морозно','Тёплая одежда и защита от ветра помогут чувствовать себя комфортно.',22,'❄️ Холодный сезон']; if($t<9)return ['Прохладно','Лёгкая куртка будет кстати. Ветер усиливает ощущение прохлады.',38,'🍂 Прохладно']; if($t<18)return ['Мягкая прохлада','На вечер пригодится дополнительный слой одежды.',55,'🌿 Умеренно']; if($t<26)return ['Тепло и приятно','Отличная температура для прогулки. Не забывайте о воде и солнцезащите.',73,'☀️ Тепло']; return ['Жарко','Ищите тень в самые солнечные часы и поддерживайте водный баланс.',91,'🌡️ Жарко']; }
function cityTimezone(string $name, float $lat=0, float $lon=0): string {
  $key = mb_strtolower(trim($name), 'UTF-8');
  $map = [
    'москва'=>'Europe/Moscow','moscow'=>'Europe/Moscow','khabarovsk'=>'Asia/Vladivostok','vladivostok'=>'Asia/Vladivostok','novosibirsk'=>'Asia/Novosibirsk','yekaterinburg'=>'Asia/Yekaterinburg','krasnoyarsk'=>'Asia/Krasnoyarsk','irkutsk'=>'Asia/Irkutsk','yakutsk'=>'Asia/Yakutsk','kaliningrad'=>'Europe/Kaliningrad','samara'=>'Europe/Samara','omsk'=>'Asia/Omsk','kazan'=>'Europe/Moscow','sochi'=>'Europe/Moscow','санкт-петербург'=>'Europe/Moscow','saint petersburg'=>'Europe/Moscow','петербург'=>'Europe/Moscow',
    'калининград'=>'Europe/Kaliningrad','архангельск'=>'Europe/Moscow','мурманск'=>'Europe/Moscow','петрозаводск'=>'Europe/Moscow','вологда'=>'Europe/Moscow','ярославль'=>'Europe/Moscow','иваново'=>'Europe/Moscow','кострома'=>'Europe/Moscow','нижний новгород'=>'Europe/Moscow','казань'=>'Europe/Moscow','самара'=>'Europe/Samara','саратов'=>'Europe/Saratov','волгоград'=>'Europe/Volgograd','астрахань'=>'Europe/Astrakhan','екатеринбург'=>'Asia/Yekaterinburg','челябинск'=>'Asia/Yekaterinburg','уфа'=>'Asia/Yekaterinburg','пермь'=>'Asia/Yekaterinburg','тюмень'=>'Asia/Yekaterinburg','сургут'=>'Asia/Yekaterinburg','омск'=>'Asia/Omsk','новосибирск'=>'Asia/Novosibirsk','барнаул'=>'Asia/Barnaul','томск'=>'Asia/Tomsk','кемерово'=>'Asia/Novokuznetsk','красноярск'=>'Asia/Krasnoyarsk','иркутск'=>'Asia/Irkutsk','улан-удэ'=>'Asia/Irkutsk','якутск'=>'Asia/Yakutsk','благовещенск'=>'Asia/Yakutsk','хабаровск'=>'Asia/Vladivostok','khabarovsk'=>'Asia/Vladivostok','владивосток'=>'Asia/Vladivostok','vladivostok'=>'Asia/Vladivostok','южно-сахалинск'=>'Asia/Sakhalin','магадан'=>'Asia/Magadan','петропавловск-камчатский'=>'Asia/Kamchatka',
    'берлин'=>'Europe/Berlin','мюнхен'=>'Europe/Berlin','гамбург'=>'Europe/Berlin','лондон'=>'Europe/London','париж'=>'Europe/Paris','рим'=>'Europe/Rome','мадрид'=>'Europe/Madrid','стамбул'=>'Europe/Istanbul','дубай'=>'Asia/Dubai','пекин'=>'Asia/Shanghai','шанхай'=>'Asia/Shanghai','токио'=>'Asia/Tokyo','сеул'=>'Asia/Seoul','нью-йорк'=>'America/New_York','new york'=>'America/New_York','лос-анджелес'=>'America/Los_Angeles','los angeles'=>'America/Los_Angeles','чикаго'=>'America/Chicago','торонто'=>'America/Toronto','ванкувер'=>'America/Vancouver','сингапур'=>'Asia/Singapore','дели'=>'Asia/Kolkata','мумбаи'=>'Asia/Kolkata','бангкок'=>'Asia/Bangkok','хельсинки'=>'Europe/Helsinki','варшава'=>'Europe/Warsaw','киев'=>'Europe/Kyiv','минск'=>'Europe/Minsk','астана'=>'Asia/Almaty','алматы'=>'Asia/Almaty','ташкент'=>'Asia/Tashkent'
  ];
  if (isset($map[$key])) return $map[$key];
  // Approximate timezone by longitude as a fallback; named-city mappings above are preferred.
  if ($lon != 0) { $offset=(int)round($lon/15); $offset=max(-12,min(14,$offset)); foreach(DateTimeZone::listIdentifiers() as $zone){try{$z=new DateTimeZone($zone);$now=new DateTimeImmutable('now',$z);if((int)$now->format('Z')===$offset*3600 && str_contains($zone,'/'))return $zone;}catch(Throwable $e){}} }
  return 'UTC';
}
function wttrLocation(array $d): array {
  $area = $d['nearest_area'][0] ?? [];
  $value = static function(string $key) use ($area): string { return trim((string)($area[$key][0]['value'] ?? '')); };
  $name=$value('areaName'); $lat=(float)($area['latitude'] ?? 0); $lon=(float)($area['longitude'] ?? 0);
  $tz = trim((string)($area['timezone'][0]['value'] ?? ''));
  if ($tz === '' || $tz === 'UTC') $tz=cityTimezone($name,$lat,$lon);
  return ['name'=>$name, 'country'=>$value('country'), 'admin'=>$value('region'), 'lat'=>$lat, 'lon'=>$lon, 'tz'=>$tz !== '' ? $tz : 'UTC'];
}
function cityDirectory(): array {
  return [
    ['Москва','Россия',55.7558,37.6173,'Europe/Moscow'],['Санкт-Петербург','Россия',59.9343,30.3351,'Europe/Moscow'],['Калининград','Россия',54.7104,20.4522,'Europe/Kaliningrad'],['Мурманск','Россия',68.9585,33.0827,'Europe/Moscow'],['Казань','Россия',55.7879,49.1233,'Europe/Moscow'],['Нижний Новгород','Россия',56.2965,43.9361,'Europe/Moscow'],['Самара','Россия',53.1959,50.1002,'Europe/Samara'],['Екатеринбург','Россия',56.8389,60.6057,'Asia/Yekaterinburg'],['Челябинск','Россия',55.1644,61.4368,'Asia/Yekaterinburg'],['Уфа','Россия',54.7388,55.9721,'Asia/Yekaterinburg'],['Пермь','Россия',58.0105,56.2502,'Asia/Yekaterinburg'],['Тюмень','Россия',57.1522,65.5272,'Asia/Yekaterinburg'],['Омск','Россия',54.9885,73.3242,'Asia/Omsk'],['Новосибирск','Россия',55.0084,82.9357,'Asia/Novosibirsk'],['Томск','Россия',56.5010,84.9925,'Asia/Tomsk'],['Красноярск','Россия',56.0153,92.8932,'Asia/Krasnoyarsk'],['Иркутск','Россия',52.2864,104.2807,'Asia/Irkutsk'],['Якутск','Россия',62.0355,129.6755,'Asia/Yakutsk'],['Хабаровск','Россия',48.4802,135.0719,'Asia/Vladivostok'],['Владивосток','Россия',43.1155,131.8855,'Asia/Vladivostok'],['Южно-Сахалинск','Россия',46.9591,142.7380,'Asia/Sakhalin'],['Петропавловск-Камчатский','Россия',53.0370,158.6559,'Asia/Kamchatka'],['Сочи','Россия',43.6028,39.7342,'Europe/Moscow'],['Краснодар','Россия',45.0355,38.9753,'Europe/Moscow'],['Ростов-на-Дону','Россия',47.2357,39.7015,'Europe/Moscow'],['Воронеж','Россия',51.6608,39.2003,'Europe/Moscow'],['Берлин','Германия',52.5200,13.4050,'Europe/Berlin'],['Лондон','Великобритания',51.5072,-0.1276,'Europe/London'],['Париж','Франция',48.8566,2.3522,'Europe/Paris'],['Нью-Йорк','США',40.7128,-74.0060,'America/New_York'],['Токио','Япония',35.6762,139.6503,'Asia/Tokyo'],['Пекин','Китай',39.9042,116.4074,'Asia/Shanghai'],['Дубай','ОАЭ',25.2048,55.2708,'Asia/Dubai'],['Минск','Беларусь',53.9006,27.5590,'Europe/Minsk'],['Алматы','Казахстан',43.2383,76.9455,'Asia/Almaty']
  ];
}
function wttrCondition(array $current): string {
  foreach (['lang_ru', 'weatherDesc'] as $key) { $v = trim((string)($current[$key][0]['value'] ?? '')); if ($v !== '') return $v; }
  return 'Переменная облачность';
}
if (isset($_GET['action'])) {
  try {
    $action=(string)$_GET['action'];
    if($action==='search') {
      $q=trim((string)($_GET['q']??''));
      if(mb_strlen($q)<2) reply(['results'=>[]]);
      $norm=mb_strtolower($q,'UTF-8'); $matches=[];
      foreach(cityDirectory() as $city){
        if(mb_stripos($city[0],$q,0,'UTF-8')!==false || mb_stripos($city[1],$q,0,'UTF-8')!==false){$matches[]=['name'=>$city[0],'country'=>$city[1],'admin'=>'','lat'=>$city[2],'lon'=>$city[3],'tz'=>$city[4]];}
      }
      if($matches){reply(['results'=>array_slice($matches,0,7)]);}
      $locationPath=str_replace('%20','+',rawurlencode($q));
      $d=httpJson((getenv('WEB_SERVICE_URL') ?: 'http://web-service:5000').'/search?location='.$locationPath);
      $loc=wttrLocation($d);
      if($loc['name']==='' || $loc['lat']==0.0 && $loc['lon']==0.0) reply(['results'=>[]]);
      reply(['results'=>[$loc]]);
    }
    if($action==='weather') {
      $lat=filter_var($_GET['lat']??null,FILTER_VALIDATE_FLOAT); $lon=filter_var($_GET['lon']??null,FILTER_VALIDATE_FLOAT);
      if($lat===false||$lon===false)reply(['error'=>'Координаты города не указаны.'],400);
      $name=trim((string)($_GET['name']??'Неизвестный город')); $country=trim((string)($_GET['country']??'')); $tz=trim((string)($_GET['tz']??'UTC'));
      $coord=sprintf('%.4F,%.4F',(float)$lat,(float)$lon);
      $d=httpJson((getenv('WEB_SERVICE_URL') ?: 'http://web-service:5000').'/weather?lat='.(float)$lat.'&lon='.(float)$lon);
      $loc=wttrLocation($d); $c=$d['current_condition'][0]??[]; $day=$d['weather'][0]??[]; $astro=$day['astronomy'][0]??[];
      if(!$c) throw new RuntimeException('wttr.in не вернул текущие погодные условия.');
      $description=wttrCondition($c); [$label,$kind]=weatherLabelFromText($description);
      $temp=(float)($c['temp_C']??0); [$cl,$ct,$fill,$chip]=climateLabel($temp);
      $result=['name'=>$loc['name']!==''?$loc['name']:$name,'country'=>$loc['country']!==''?$loc['country']:$country,'lat'=>$loc['lat']?: (float)$lat,'lon'=>$loc['lon']?: (float)$lon,'tz'=>$loc['tz']!=='UTC'?$loc['tz']:$tz,'temp'=>round($temp),'feel'=>round((float)($c['FeelsLikeC']??$temp)),'humidity'=>(int)($c['humidity']??0),'wind'=>round((float)($c['windspeedKmph']??0)),'pressure'=>round((float)($c['pressure']??1013)),'uv'=>round((float)($c['uvIndex']??0),1),'weather'=>$label,'kind'=>$kind,'climate'=>$cl,'climateText'=>$ct,'climateFill'=>$fill,'climateChip'=>$chip,'sunrise'=>trim((string)($astro['sunrise']??'—')),'sunset'=>trim((string)($astro['sunset']??'—')),'fetchedAt'=>date(DATE_ATOM)];
      if($pdo){ try{$st=$pdo->prepare('INSERT INTO weather_requests (city,country,temperature,weather_description,climate_description,requested_at) VALUES (?,?,?,?,?,NOW())');$st->execute([$name,$country,$result['temp'],$label,$cl]);$result['historyId']=(int)$pdo->lastInsertId();}catch(Throwable $e){$result['dbWriteFailed']=true;} }
      reply(['weather'=>$result,'dbConnected'=>(bool)$pdo,'dbError'=>$dbError]); }
    if($action==='clearHistory') { if($pdo) { $pdo->exec('DELETE FROM weather_requests'); } reply(['ok'=>true,'dbConnected'=>(bool)$pdo,'dbError'=>$dbError]); }
    if($action==='history') { if(!$pdo)reply(['items'=>[],'dbConnected'=>false,'dbError'=>$dbError]); $st=$pdo->query('SELECT city,country,temperature,weather_description,climate_description,requested_at FROM weather_requests ORDER BY id DESC LIMIT 10'); reply(['items'=>$st->fetchAll(),'dbConnected'=>true]); }
    reply(['error'=>'Неизвестное действие.'],404);
  } catch(Throwable $e) { reply(['error'=>$e->getMessage()],502); }
}

if (!isset($_GET['action'])) { reply(['error'=>'Укажите действие API.'], 404); }
?>
