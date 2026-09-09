import { loadControllers, startStimulusApp } from '@symfony/stimulus-bundle';

// On every page, so loading these on demand would cost a round trip after the entrypoint has parsed.
import CosmeticsToggleController from './controllers/application/cosmetics_toggle_controller.ts';
import DismissibleController from './controllers/application/dismissible_controller.ts';
import NavDropdownController from './controllers/application/nav_dropdown_controller.ts';
import NotificationsController from './controllers/application/notifications_controller.ts';

const app = startStimulusApp();

// Registered with flat identifiers so the templates keep using `data-controller="localised-fields"` etc. despite the
// subdirectories -- the path-based autoload would otherwise namespace them (e.g. `application--localised-fields`). The
// framework-scaffolded csrf_protection controller stays at the controllers/ root and autoloads as `csrf-protection`.
//
// The rest are imported dynamically: the asset mapper preloads whatever an entrypoint imports statically, so a static
// import here would emit a `modulepreload` on every page. The `stimulusFetch` comment in each controller does the
// same for its autoloaded alias.
loadControllers(
    app,
    {
        'cosmetics-toggle': CosmeticsToggleController,
        'dismissible': DismissibleController,
        'nav-dropdown': NavDropdownController,
        'notifications': NotificationsController,
    },
    {
        // Application-wide, domain-agnostic controllers.
        'confirm-modal': () => import('./controllers/application/confirm_modal_controller.ts'),
        'copy': () => import('./controllers/application/copy_controller.ts'),
        'description-toggle': () => import('./controllers/application/description_toggle_controller.ts'),
        'edit-lock': () => import('./controllers/application/edit_lock_controller.ts'),
        'form-collection': () => import('./controllers/application/form_collection_controller.ts'),
        'image-crop': () => import('./controllers/application/image_crop_controller.ts'),
        'infinite-scroll': () => import('./controllers/application/infinite_scroll_controller.ts'),
        'localised-fields': () => import('./controllers/application/localised_fields_controller.ts'),
        'markdown-editor': () => import('./controllers/application/markdown_editor_controller.ts'),
        'modal-close': () => import('./controllers/application/modal_close_controller.ts'),
        'modal-form-target': () => import('./controllers/application/modal_form_target_controller.ts'),
        'navigate-select': () => import('./controllers/application/navigate_select_controller.ts'),
        'notification-settings': () => import('./controllers/application/notification_settings_controller.ts'),
        'print': () => import('./controllers/application/print_controller.ts'),
        'sortable': () => import('./controllers/application/sortable_controller.ts'),
        'submit-once': () => import('./controllers/application/submit_once_controller.ts'),

        // User-specific controllers.
        'external-app-signing': () => import('./controllers/user/external_app_signing_controller.ts'),

        // Activity-specific controllers.
        'activity-item': () => import('./controllers/activity/activity_item_controller.ts'),
        'announcement-placeholder': () => import('./controllers/activity/announcement_placeholder_controller.ts'),
        'signup-field': () => import('./controllers/activity/signup_field_controller.ts'),
        'signup-list': () => import('./controllers/activity/signup_list_controller.ts'),
        'tier-order': () => import('./controllers/activity/tier_order_controller.ts'),

        // Frontpage-specific controllers.
        'birthday-rotator': () => import('./controllers/frontpage/birthday_rotator_controller.ts'),
        'infimum': () => import('./controllers/frontpage/infimum_controller.ts'),
        'page-editor': () => import('./controllers/frontpage/page_editor_controller.ts'),
        'page-images': () => import('./controllers/frontpage/page_images_controller.ts'),

        // Decision-specific controllers.
        'counterpart-modal': () => import('./controllers/decision/counterpart_modal_controller.ts'),
        'decision-counterpart': () => import('./controllers/decision/decision_counterpart_controller.ts'),
        'decision-lookup': () => import('./controllers/decision/decision_lookup_controller.ts'),
        'decision-number': () => import('./controllers/decision/decision_number_controller.ts'),
        'document-upload': () => import('./controllers/decision/document_upload_controller.ts'),
        'foundation-form': () => import('./controllers/decision/foundation_form_controller.ts'),
        'install-editor': () => import('./controllers/decision/install_editor_controller.ts'),
        'live-sortable': () => import('./controllers/decision/live_sortable_controller.ts'),
        'meeting-lookup': () => import('./controllers/decision/meeting_lookup_controller.ts'),
        'member-lookup': () => import('./controllers/decision/member_lookup_controller.ts'),
        'member-search': () => import('./controllers/decision/member_search_controller.ts'),
        'organ-lookup': () => import('./controllers/decision/organ_lookup_controller.ts'),
        'organ-members': () => import('./controllers/decision/organ_members_controller.ts'),
        'revision-filter': () => import('./controllers/decision/revision_filter_controller.ts'),
        'subdecision-choice': () => import('./controllers/decision/subdecision_choice_controller.ts'),

        // Join-specific controllers.
        'initials': () => import('./controllers/join/initials_controller.ts'),
        'study-notice': () => import('./controllers/join/study_notice_controller.ts'),

        // Photo-specific controllers.
        'album-search': () => import('./controllers/photo/album_search_controller.ts'),
        'photo-cover': () => import('./controllers/photo/cover_controller.ts'),
        'gallery': () => import('./controllers/photo/gallery_controller.ts'),
        'photo-upload': () => import('./controllers/photo/upload_controller.ts'),

        // Query-specific controllers.
        'query-editor': () => import('./controllers/query/query_editor_controller.ts'),
    },
);
