<?php defined('SYSPATH') or die('No direct script access.');

class ServiceException extends Exception {

	private $type;

	public function __construct($message, $type, ?Exception $previous = null)
	{
		parent::__construct($message, 0, $previous);
		$this->type = $type;
	}

	public function getType()
	{
		return $this->type;
	}

	public function __toString()
	{
		return $this->getMessage() . " ($this->type)";
	}

}


/**
 * Коннектор к серверу GDS
 *
 * @author V.Skorykh
 * @since 02.02.2016 11:32
 *
 */
class Gate_Gds_Server
{

	private $url;
	private $client;

	function __construct($url, $login, $password)
	{

		$this->url = $url;
        ini_set('default_socket_timeout', 5000);

		$opts = array(
            'connection_timeout' => 5000,
            'keep_alive' => false,

			'login' => $login,
			'password' => $password,
			'authentication' => SOAP_AUTHENTICATION_BASIC,
			'features' =>  SOAP_SINGLE_ELEMENT_ARRAYS,
			'cache_wsdl' => WSDL_CACHE_MEMORY,
			'trace' => TRUE,
			'exceptions' => TRUE,
			'classmap' => array(
				'country' => 'Gate_Gds_Country',
				'region' => 'Gate_Gds_Region',
				'point' => 'Gate_Gds_Point',
				'race' => 'Gate_Gds_Race',
				'raceType' => 'Gate_Gds_RaceType',
				'raceClass' => 'Gate_Gds_RaceClass',
				'raceStatus' => 'Gate_Gds_RaceStatus',
				'raceSummary' => 'Gate_Gds_RaceSummary',
				'depotInfo' => 'Gate_Gds_Depot',
				'stop' => 'Gate_Gds_Stop',
				'seat' => 'Gate_Gds_Seat',
				'ticketType' => 'Gate_Gds_TicketType',
				'docType' => 'Gate_Gds_DocType',
				'order' => 'Gate_Gds_Order',
				'ticket' => 'Gate_Gds_Ticket',
				'user' => 'Gate_Gds_User',
				'company' => 'Gate_Gds_Company',
				'benefit' => 'Gate_Gds_Benefit',
				'reference' => 'Gate_Gds_Reference',
				'referenceItem' => 'Gate_Gds_ReferenceItem',
			)
		);

		$this->client = new SoapClient($url, $opts);
	}

	private function as_object($response) {
		return isset($response->return) ? $response->return : NULL;
	}

	private function as_ticket($ticket) {
		$ticket->dispatchDate = $this->decode_datetime($ticket->dispatchDate);
		$ticket->arrivalDate = $this->decode_datetime($ticket->arrivalDate);
		$ticket->birthday = $this->decode_date($ticket->birthday);
		$ticket->created = $this->decode_datetime($ticket->created);
		$ticket->returned = $this->decode_datetime($ticket->returned);
		return $ticket;
	}

	private function as_order($order) {
		$order->created = $this->decode_datetime($order->created);
		$order->finished = $this->decode_datetime($order->finished);
		$order->expired = $this->decode_datetime($order->expired);
		foreach ($order->tickets as $key => $ticket) {
			$order->tickets[$key] = $this->as_ticket($order->tickets[$key]);
		}
		return $order;
	}

	private function decode_date($date) {
		$dt = DateTime::createFromFormat(DateTime::W3C, $date);
		return ($dt !== FALSE)?$dt->format('Y-m-d'):NULL;
	}

	private function decode_datetime($date) {
		// Дата вида 2017-02-01T15:08:00 (дата отправления рейса на JDK 1.8)
		$dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $date);
		if ($dt === FALSE) {
			// Дата вида 2017-02-01T15:08:00+07:00 (остановки)
			$dt = DateTime::createFromFormat(DateTime::W3C, $date);
		}
		if ($dt === FALSE) {
			// Дата вида 2017-02-01T15:08:00.000000+07:00
			$dt = DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $date);
		}
		// Результат возвращается в виде 2017-02-01 15:08:00
		return ($dt !== FALSE)?$dt->format('Y-m-d H:i:s'):NULL;
	}

	/**
	 * Отправка эхо-запроса на сервер
	 *
	 * @param string $value Исходное значение
	 * @return string|null Эхо-значение, равное исходному значению.
	 */
	public function service_echo($value)
	{
		$response = $this->client->echo(array('message' => $value));
		return $this->as_object($response);
	}

	/**
	 * Получить информацию о версии сервера
	 *
	 * @return string Текущая версия сервера
	 */
	public function get_version()
	{
		$response = $this->client->getVersion();
		return $this->as_object($response);
	}

	/**
	 * Получить список автовокзалов, доступных пользователю
	 *
	 * @return Gate_Gds_Depot[] Информация об автовокзалах
	 */
	public function get_depots()
	{
		$response = $this->client->getDepots();
		return $this->as_object($response);
	}

	/**
	 * Получение информации об автовокзале
	 *
	 * @param $depot_id
	 * @return Gate_Gds_Depot Информация об автовокзале
	 */
	public function get_depot_info($depot_id) {
		$response = $this->client->getDepotInfo(array(
			'depotId' => $depot_id
		));
		return $this->as_object($response);
	}

	/**
	 * Получить список стран, для которых доступна возможность продажи билетов
	 *
	 * @return Gate_Gds_Country[] Список стран
	 */
	public function get_countries()
	{
		$response = $this->client->getCountries();
		return $this->as_object($response);
	}

	/**
	 * Получение списка регионов страны
	 * @param integer $country_id ID страны
	 * @return Gate_Gds_Region[]
	 */
	public function get_regions($country_id) {
		$response = $this->client->getRegions(array('countryId' => $country_id));
		return $this->as_object($response);
	}

	/**
	 * Получение списка мест отправления
	 *
	 * @param integer $region_id ID региона. Если 0, то поступает список для всех регионов
	 * @return Gate_Gds_Point[] Список пунктов отправления
	 */
	public function get_dispatch_points($region_id = 0) {
		$response = $this->client->getDispatchPoints(array('regionId' => $region_id));
		return $this->as_object($response);
	}

	/**
	 * Получение списке мест прибытия
	 *
	 * @param integer $dispatch_point_id ID пункта отправления
	 * @param string $pattern Образец названия станции для поиска. Если null или пустая строка - возвращаются все записи
	 * @return Gate_Gds_Point[] Список пунктов прибытия
	 */
	public function get_arrival_points($dispatch_point_id, $pattern = NULL) {
		$response = $this->client->getArrivalPoints(array('dispatchPointId' => $dispatch_point_id, 'pattern' => $pattern));
		return $this->as_object($response);
	}

	/**
	 * Получение списка рейсов
	 *
	 * @param integer $dispatch_point_id Пункт отправления
	 * @param integer $arrival_point_id Пункт прибытия
	 * @param string $date Дата поездки в формате Y-m-d
	 * @return Gate_Gds_Race[] Список рейсов
	 */
	public function get_races($dispatch_point_id, $arrival_point_id, $date) {
		$response = $this->client->getRaces(array(
			'dispatchPointId' => $dispatch_point_id,
			'arrivalPointId' => $arrival_point_id,
			'date' => $date
		));
		$races = $this->as_object($response);
		if ($races === null) {
			return array();
		}
		if (!is_array($races)) {
			$races = array($races);
		}
		for ($i = 0; $i < count($races); $i++) {
			$races[$i]->dispatchDate = $this->decode_datetime($races[$i]->dispatchDate);
			$races[$i]->arrivalDate = $this->decode_datetime($races[$i]->arrivalDate);
		}
		return $races;
	}

	/**
	 * Получение списка рейсов за диапазон дат
	 *
	 * @param integer $dispatch_point_id Пункт отправления
	 * @param integer $arrival_point_id Пункт прибытия
	 * @param string $date_from Дата начала периода в формате Y-m-d
	 * @param string $date_till Дата конца периода в формате Y-m-d
	 * @return Gate_Gds_Race[] Список рейсов
	 */
	public function get_all_races($dispatch_point_id, $arrival_point_id, $date_from, $date_till) {
		$response = $this->client->getAllRaces(array(
			'dispatchPointId' => $dispatch_point_id,
			'arrivalPointId' => $arrival_point_id,
			'dateFrom' => $date_from,
			'dateTill' => $date_till
		));
		$races = $this->as_object($response);
		if ($races === null) {
			return array();
		}
		if (!is_array($races)) {
			$races = array($races);
		}
		for ($i = 0; $i < count($races); $i++) {
			$races[$i]->dispatchDate = $this->decode_datetime($races[$i]->dispatchDate);
			$races[$i]->arrivalDate = $this->decode_datetime($races[$i]->arrivalDate);
		}
		return $races;
	}

	/**
	 * Получение информации о рейсе. Информация изврекается из кэша, формируемого запросом get_races().
	 * Вызывать get_race() до вызова get_races() нельзя!
	 *
	 * @param string $race_uid Универсальный идентификатор рейса
	 * @return Gate_Gds_Race Информация о рейсе из кэша
	 */
	public function get_race($race_uid) {
		$response = $this->client->getRace(array(
			'uid' => $race_uid
		));
		$race = $this->as_object($response);
		$race->dispatchDate = $this->decode_datetime($race->dispatchDate);
		$race->arrivalDate = $this->decode_datetime($race->arrivalDate);
		return $race;
	}

	/**
	 * Получение сводной информации о рейсе. Вызов данного метода заменяет последовательные вызовы методов
	 * getRace(), getRaceStops(), getRaceSeats(), getTicketTypes() и getDocTypes()
	 *
	 * @param string $race_uid Универсальный идентификатор рейса
	 * @return Gate_Gds_RaceSummary Сводная информация по рейсу
	 */
	public function get_race_summary($race_uid) {
		$response = $this->client->getRaceSummary(array(
			'uid' => $race_uid
		));
		$summary = $this->as_object($response);
		$summary->race->dispatchDate = $this->decode_datetime($summary->race->dispatchDate);
		$summary->race->arrivalDate = $this->decode_datetime($summary->race->arrivalDate);
		foreach ($summary->stops as $key => $stop) {
			$summary->stops[$key]->dispatchDate = $this->decode_datetime($stop->dispatchDate);
			$summary->stops[$key]->arrivalDate = $this->decode_datetime($stop->arrivalDate);
		}
		return $summary;
	}

	/**
	 * Получение списка остановок рейса
	 *
	 * @param string $race_uid Универсальный идентификатор рейса
	 * @return Gate_Gds_Stop[] Список остановок
	 */
	public function get_race_stops($race_uid) {
		$response = $this->client->getRaceStops(array(
			'uid' => $race_uid
		));
		return $this->as_object($response);
	}

	/**
	 * Получение списка свободных мест
	 *
	 * @param string $race_uid Универсальный идентификатор рейса
	 * @return Gate_Gds_Seat[] Список мест
	 */
	public function get_race_seats($race_uid) {
		$response = $this->client->getRaceSeats(array(
			'uid' => $race_uid
		));
		return $this->as_object($response);
	}

	/**
	 * Получение списка типов билетов
	 *
	 * @param string $race_uid Универсальный идентификатор рейса
	 * @return Gate_Gds_TicketType[] Список допустимых типов билетов и цены
	 */
	public function get_ticket_types($race_uid) {
		$response = $this->client->getTicketTypes(array(
			'uid' => $race_uid
		));
		return $this->as_object($response);
	}

	/**
	 * Получение списка типов документов
	 *
	 * @param string $race_uid Универсальный идентификатор рейса
	 * @return Gate_Gds_DocType[] Типы документов
	 */
	public function get_doc_types($race_uid) {
		$response = $this->client->getDocTypes(array(
			'uid' => $race_uid
		));
		return $this->as_object($response);
	}

	/**
	 * Бронировение билетов.
	 *
	 * @param integer $depot_id ID автовокзала
	 * @param string $race_uid Универсальный идентификатор рейса
	 * @param Gate_Gds_Sale[] $sales Информация о бронируемых билетах, массив объектов класса Sale
	 * @return Gate_Gds_Order Информация о забронированном заказе и билетах
	 */
	public function book_order($depot_id, $race_uid, $sales) {
		$response = $this->client->bookOrder(array(
			'depotId' => $depot_id,
			'uid' => $race_uid,
			'sales' => $sales,
		));
		return $this->as_order($this->as_object($response));
	}

	/**
	 * Подтверждение продажи билета. Данный метод должен вызываться только после того, как заказ действительно был
	 * оплачен покупателем.
	 *
	 * @param integer $order_id ID забронированного заказа
	 * @param string $payment_method Способ оплаты. Начиная с версии 1.8.6 допустимые значения: "Наличный расчет" -
	 * 					при оплате наличными, "По банковской карте" - при безналичной оплате
	 * @return Gate_Gds_Order Информация о проданном заказе и билетах
	 */
	public function confirm_order($order_id, $payment_method) {
		$response = $this->client->confirmOrder(array(
			'orderId' => $order_id,
			'paymentMethod' => $payment_method
		));
		return $this->as_order($this->as_object($response));
	}

	/**
	 * Подтверждение продажи билета с возможностью изменить тариф.
	 * Изменение тарифа возможно только для указанного заказа и только при наличии прямого договора между Агентом и АТП.
	 * Метод может использоваться только по согласованию с Артмарк, в остальных случаях надо использовать confirm_order()
	 *
	 * @param integer $order_id
	 * @param string $payment_method
	 * @param Gate_Gds_TicketFare[] $fares
	 * @param string $comment Примечание
	 * @return Gate_Gds_Order Информация о проданном заказе и билетах
	 * @since 1.12.1
	 */
	public function confirm_order_with_fare($order_id, $payment_method, $fares, $comment) {
		$response = $this->client->confirmOrderWithFare(array(
			'orderId' => $order_id,
			'paymentMethod' => $payment_method,
			'fares' => $fares,
			'comment' => $comment
		));
		return $this->as_order($this->as_object($response));
	}

	/**
	 * Получение информации о заказе.
	 *
	 * @param integer $order_id ID заказа
	 * @return Gate_Gds_Order Информация о заказе и билетах
	 */
	public function get_order($order_id) {
		$response = $this->client->getOrder(array(
			'orderId' => $order_id,
		));
		return $this->as_order($this->as_object($response));
	}

	/**
	 * Получение информации о билете.
	 *
	 * @param integer $ticket_id ID билета
	 * @return Gate_Gds_Ticket Билет
	 */
	public function get_ticket($ticket_id) {
		$response = $this->client->getTicket(array(
			'ticketId' => $ticket_id,
		));
		return $this->as_ticket($this->as_object($response));
	}

	/**
	 * Обновление информации о пассажире в забронированном или проданном билете.
	 *
	 * @param integer $ticket_id ID Билета
	 * @param Gate_Gds_Sale $sale Информация о продаже. Изменение типа билета и номера места не поддерживается.
	 * @return Gate_Gds_Ticket
	 * @since 1.7
	 */
	public function update_ticket($ticket_id, $sale) {
		$response = $this->client->updateTicket(array(
			'ticketId' => $ticket_id,
			'sale' => $sale
		));
		return $this->as_ticket($this->as_object($response));
	}

	/**
	 * Возврат билета.
	 *
	 * @param integer $ticket_id ID билета
	 * @return Gate_Gds_Ticket Информация о билете
	 */
	public function return_ticket($ticket_id) {
		$response = $this->client->returnTicket(array(
			'ticketId' => $ticket_id,
		));
		return $this->as_ticket($this->as_object($response));
	}

	/**
	 * Отмена заказа. Возможна в AVServer в течение ограниченного периода времени.
	 * Удержания при отмене заказа не производятся.
	 *
	 * @param integer $order_id ID заказа
	 * @return Gate_Gds_Order Информация об отмененном заказе
	 */
	public function cancel_order($order_id) {
		$response = $this->client->cancelOrder(array(
			'orderId' => $order_id,
		));
		return $this->as_order($this->as_object($response));
	}

	/**
	 * Отмена билета. Возможна только в AVS5 в течение ограниченного периода времени
	 *
	 * @since 1.10
	 * @param integer $ticket_id ID билета
	 * @return Gate_Gds_Ticket Информация о билете
	 */
	public function cancel_ticket($ticket_id) {
		$response = $this->client->cancelTicket(array(
			'ticketId' => $ticket_id,
		));
		return $this->as_ticket($this->as_object($response));
	}

	/**
	 * Получение информации из справочника
	 *
	 * @param string $reference_code Код справочника
	 * @return Gate_Gds_Reference Данные справочника
	 */
	public function get_reference($reference_code) {
		$response = $this->client->getReference(array(
			'code' => $reference_code,
		));
		return $this->as_object($response);
	}

}

