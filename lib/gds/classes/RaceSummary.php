<?php defined('SYSPATH') or die('No direct script access.');


/**
 * Сводная информация по рейсу
 *
 * @author V.Skorykh
 * @since 12.02.2016 16:05
 */
class Gate_Gds_RaceSummary
{
	/**
	 * @var Gate_Gds_Depot Информация об автовокзале, с которого отправляется рейс
	 *
	 * @since 1.10
	 */
	public $depot;

	/**
	 * @var Gate_Gds_Race Информация о рейсе с уточненным статусом рейса.
	 */
	public $race;

	/**
	 * @var Gate_Gds_Stop[] Список остановок на маршруте. Имеет значение null, если возможность не поддерживается.
	 */
	public $stops;

	/**
	 * @var Gate_Gds_Seat[] Список свободных мест. Имеет значение null, если возможность не поддерживается.
	 */
	public $seats;

	/**
	 * @var Gate_Gds_TicketType[] Списоку типов билетов
	 */
	public $ticketTypes;

	/**
	 * @var Gate_Gds_DocType[] Список типов документов
	 */
	public $docTypes;
}