<?php
/**
 * Domain event bus.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Core\Events;

/**
 * Dispatches domain events to listeners.
 *
 * Modules subscribe to events rather than calling each other. The Leads
 * module reacts to ConversationEnded without knowing the Chat module
 * exists, which is what keeps modules independently removable.
 */
final class EventBus {

	/**
	 * Listeners keyed by event class, ordered by priority.
	 *
	 * @var array<class-string, array<int, array<int, callable(object): void>>>
	 */
	private array $listeners = array();

	/**
	 * Subscribe to an event.
	 *
	 * @param class-string             $event    Event class name.
	 * @param callable(object): void   $listener Listener.
	 * @param int                      $priority Lower runs earlier.
	 * @return void
	 */
	public function listen( string $event, callable $listener, int $priority = 10 ): void {
		$this->listeners[ $event ][ $priority ][] = $listener;
	}

	/**
	 * Dispatch an event to its listeners.
	 *
	 * A throwing listener must not prevent the others from running, nor
	 * break the request that dispatched the event. Failures are logged
	 * and swallowed.
	 *
	 * @param object $event Event instance.
	 * @return object The event, for chaining.
	 */
	public function dispatch( object $event ): object {
		$name = $event::class;

		if ( ! isset( $this->listeners[ $name ] ) ) {
			return $event;
		}

		$byPriority = $this->listeners[ $name ];
		ksort( $byPriority );

		foreach ( $byPriority as $group ) {
			foreach ( $group as $listener ) {
				try {
					$listener( $event );
				} catch ( \Throwable $e ) {
					do_action( 'hiveclerk/event/listener_failed', $name, $e );
				}
			}
		}

		return $event;
	}

	/**
	 * Whether an event has listeners.
	 *
	 * @param class-string $event Event class name.
	 * @return bool
	 */
	public function hasListeners( string $event ): bool {
		return ! empty( $this->listeners[ $event ] );
	}
}
