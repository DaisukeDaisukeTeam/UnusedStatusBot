<?php
declare(strict_types=1);

namespace pmmpDiscordBot;

use pocketmine\event\Event;

class GameStatusCollectionEvent extends Event{
	protected CollectorTask $task;
	/** @var array<string, list<string>> */
	protected array $collection = [];

	public function __construct(CollectorTask $task){
		$this->task = $task;
	}

	public function addGame(string $name, string $column) : void{
		$this->collection[$name][] = $column;
	}

	/**
	 * @return array<string, list<string>>
	 */
	public function getCollection() : array{
		return $this->collection;
	}
}