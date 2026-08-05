<?php
/**
 * Create a clerk so the widget can be exercised before Sprint 6.
 *
 * Sprint 6 builds AgentService and the clerk editor. Until then there is no
 * supported way to create a clerk, and the chat path cannot be run against
 * a real provider without one — so this exists to unblock the M2 demo and
 * nothing else. It is a development tool, not a fixture and not a seeder
 * anyone should point at a customer's site: it publishes a clerk that will
 * answer visitors the moment it is written.
 *
 * Run with:
 *   wp eval-file tools/seed-clerk.php [provider] [model] [source-id]
 *
 * No declare(strict_types=1): wp eval-file evals this file's contents, and
 * a strict_types declaration is only legal as a script's first statement.
 *
 * @package Hiveclerk
 */

use Hiveclerk\Domain\Agent\Agent;
use Hiveclerk\Domain\Agent\AgentRepositoryInterface;
use Hiveclerk\Domain\Agent\AgentStatus;
use Hiveclerk\Domain\Shared\Uuid;
use Hiveclerk\Plugin;

$hvc_args     = $args ?? array();
$hvc_provider = $hvc_args[0] ?? 'google';
$hvc_model    = $hvc_args[1] ?? 'gemini-2.5-flash';
$hvc_source   = isset( $hvc_args[2] ) ? (int) $hvc_args[2] : 1;

$hvc_agents = Plugin::instance()->container()->get( AgentRepositoryInterface::class );
$hvc_agent  = $hvc_agents->findBySlug( 'ada' );

if ( null === $hvc_agent ) {
	$hvc_agent = new Agent(
		id: null,
		uuid: Uuid::generate(),
		name: 'Ada',
		slug: 'ada'
	);
}

$hvc_agent->status          = AgentStatus::Published;
$hvc_agent->rolePreset      = 'support';
$hvc_agent->greeting        = 'Hi — what can I help you find?';
$hvc_agent->fallbackMessage = "I don't have that in what I've been given to read. "
	. 'Leave your email and someone here will follow it up.';
$hvc_agent->instructions    = 'You answer questions about this website using only the reference '
	. 'material you are given. Be concise and concrete.';
$hvc_agent->modelConfig     = array(
	'provider'    => $hvc_provider,
	'model'       => $hvc_model,
	'temperature' => 0.3,
	'max_tokens'  => 600,
);
$hvc_agent->guardrails      = array(
	'no_invent_facts'      => true,
	'confidence_threshold' => 0.55,
	'top_k'                => 5,
);
$hvc_agent->widgetConfig    = array(
	'position'       => 'bottom-right',
	'accent'         => '#2B4ACB',
	'radius'         => 16,
	'theme'          => 'auto',
	'launcher_label' => 'Ask Ada',
	'show_badge'     => true,
);

$hvc_agent = $hvc_agents->save( $hvc_agent );

if ( null === $hvc_agent->id ) {
	echo "Could not save the clerk.\n";
	exit( 1 );
}

if ( $hvc_source > 0 ) {
	$hvc_agents->attachSource( $hvc_agent->id, $hvc_source );
}

printf(
	"Clerk \"%s\" is on duty.\n  uuid     %s\n  provider %s / %s\n  sources  %s\n",
	$hvc_agent->name,
	$hvc_agent->uuid->value,
	$hvc_provider,
	$hvc_model,
	implode( ', ', $hvc_agents->sourceIds( $hvc_agent->id ) ) ?: 'none'
);
