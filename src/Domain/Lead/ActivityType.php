<?php
/**
 * Activity type.
 *
 * @package Hiveclerk
 */

declare( strict_types=1 );

namespace Hiveclerk\Domain\Lead;

/**
 * What happened, in the vocabulary the timeline renders.
 *
 * A closed set rather than a free string. The timeline is the screen a
 * salesperson reads before picking up the phone, and one mistyped type
 * from a third-party integration would put an unlabelled row in the
 * middle of it.
 */
enum ActivityType: string {

	case PageView            = 'page_view';
	case ConversationStarted = 'conversation_started';
	case MessageSent         = 'message_sent';
	case LeadCaptured        = 'lead_captured';
	case ScoreChanged        = 'score_changed';
	case StageChanged        = 'stage_changed';
	case StatusChanged       = 'status_changed';
	case NoteAdded           = 'note_added';
	case HandoffRequested    = 'handoff_requested';
	case NotificationSent    = 'notification_sent';
	case LeadMerged          = 'lead_merged';
	case EmailSent           = 'email_sent';
	case EmailOpened         = 'email_opened';
	case EmailClicked        = 'email_clicked';
	case CrmSynced           = 'crm_synced';

	/**
	 * Whether this happened before the visitor was a lead.
	 *
	 * Pre-identification activity is written against the visitor and is
	 * carried onto the lead when stitching resolves who they were, which
	 * is what makes "viewed /pricing (2nd)" appear above "lead captured"
	 * rather than being lost with the anonymous session.
	 *
	 * @return bool
	 */
	public function isVisitorScoped(): bool {
		return self::PageView === $this;
	}

	/**
	 * Parse a stored value.
	 *
	 * @param string|null $value Stored value.
	 * @return self|null Null when the stored type is not one we render.
	 */
	public static function fromStorage( ?string $value ): ?self {
		return self::tryFrom( (string) $value );
	}
}
