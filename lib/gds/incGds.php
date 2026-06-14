<?php

define('SYSPATH', dirname(__FILE__));
define('DEBUG_MODE', false);


require __DIR__ . '/classes/Tools.php';
require __DIR__ . '/classes/Server.php';
require __DIR__ . '/classes/Depot.php';
require __DIR__ . '/classes/Country.php';
require __DIR__ . '/classes/Region.php';
require __DIR__ . '/classes/Point.php';
require __DIR__ . '/classes/Race.php';
require __DIR__ . '/classes/RaceType.php';
require __DIR__ . '/classes/RaceClass.php';
require __DIR__ . '/classes/RaceStatus.php';
require __DIR__ . '/classes/RaceSummary.php';
require __DIR__ . '/classes/Stop.php';
require __DIR__ . '/classes/Seat.php';
require __DIR__ . '/classes/TicketType.php';
require __DIR__ . '/classes/DocType.php';
require __DIR__ . '/classes/Sale.php';
require __DIR__ . '/classes/Benefit.php';
require __DIR__ . '/classes/Order.php';
require __DIR__ . '/classes/Ticket.php';
require __DIR__ . '/classes/TicketFare.php';
require __DIR__ . '/classes/Company.php';
require __DIR__ . '/classes/User.php';
require __DIR__ . '/classes/Reference.php';
require __DIR__ . '/classes/ReferenceItem.php';



date_default_timezone_set('Europe/Moscow');

$server_url = 'http://cluster.avtovokzal.ru/gds113';
$url = 'http://cluster.avtovokzal.ru/gds113/soap/sales?wsdl';
$login = getenv('GDS_LOGIN') ?: 'intercitytour';
// Пароль GDS берём из окружения — в коде не храним. Источник: /etc/panel.env (GDS_PASSWORD).
$password = getenv('GDS_PASSWORD') ?: '';
if ($password === '' && function_exists('env_get')) {
    $password = env_get('GDS_PASSWORD');
}
if ($password === '' && is_readable('/etc/panel.env')) {
    foreach (file('/etc/panel.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $__envLine) {
        if (str_starts_with($__envLine, 'GDS_PASSWORD=')) { $password = substr($__envLine, strlen('GDS_PASSWORD=')); break; }
    }
}

$dispatch_region_name = '';
$dispatch_point_name = '';
$arrival_point_name = '';

$race_index = 0;
$server = new Gate_Gds_Server($url, $login, $password);
$GLOBALS['server'] = $server;

// Получение списка стран
// $countries = $server->get_countries();
// $country = find_by_code($countries, 'RU');
// if (empty($country)) {
// 	println('Страна не найдена');
// 	exit;
// }

?>
