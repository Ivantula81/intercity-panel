<?php defined('SYSPATH') or die('No direct script access.');


/**
 * Тип билета в подсистеме GDS
 *
 * @author V.Skorykh
 * @since 02.02.2016 12:41
 */
class Gate_Gds_TicketType
{
	const CLASS_PASSENGER = 'P';
	const CLASS_BAGGAGE = 'B';

	public $code;
	public $name;
	public $price;
	public $ticketClass;

	/**
	 * Поиск нужного типа билета в списке
	 *
	 * @param Gate_Gds_TicketType[] $ticket_types Информация о типах продаваемых билетов
	 * @param string $ticket_type_code Код типа билета
	 * @return Gate_Gds_TicketType|null
	 */
	public static function find($ticket_types, $ticket_type_code) {
		foreach ($ticket_types as $ticket_type) {
			if ($ticket_type->code == $ticket_type_code) {
				return $ticket_type;
			}
		}
		return NULL;
	}

}