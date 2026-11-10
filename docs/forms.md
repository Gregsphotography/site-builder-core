# Optional enquiry forms

Forms are disabled by default. They support contact/enquiry fields, not attachments, payments, workflows, conditional logic, autoresponders or multi-step forms. The defaults are name/email/message; phone/subject and at most ten additional text/email/tel/textarea/select/checkbox fields are supported (15 total). Configure recipients in admin-managed notification profiles, never in a public block.

Install explicitly in a backed-up host:

```sh
php artisan site-builder:forms-install
# Review the published migration/config, then run the host migrations deliberately.
php artisan migrate
php artisan site-builder:forms-doctor --json
```

The installer preserves existing files, creates a new installation UUID only when configuration is absent, and never enables forms or runs migrations. Set `site-builder-forms.enabled` after integrating policies and operations. Retain that UUID across releases/restores of the same site; a different installation must get a new one. Content export carries only an opaque profile/installation binding; profiles, recipients, submissions and notification records are excluded. Imported foreign bindings require local rebinding before publication. Do not export the host config or inbox with content.

Register explicit host policies for `SiteBuilder\Components\Forms\NotificationProfile` (viewAny/create/update/delete/use) and `SiteBuilder\Components\Forms\Submission` (viewAny/view/update/delete/retry). No permissions are inferred from login alone. To show admin management in an existing authenticated Filament panel, add `->forms()` to `SiteBuilderPlugin::make()`; this is separate from `->mediaLibrary()`. Authors with `use` permission can choose an active profile by display name; management and record actions enforce their own policies. Profile edits detect stale versions and retain pending input. Empty recipients mean inbox-only.

Use the normal web/session/CSRF middleware and shared throttling cache. Active form pages contain a session token and signed/encrypted expiring occurrence context. PageResponse emits `Cache-Control: private, no-store`; exclude these pages from CDN/full-page caches. Custom fragment hosts must honor `PublicAssets::privateResponse()` and add equivalent private headers; do not put session-specific HTML in a shared cache. Submit requests re-resolve the current published page, locale and visible form occurrence, validate the current schema and active local profile, and reject stale/unpublished/hidden forms. Failed validation retains the visitor's values; the honeypot does not create a submission. Duplicate same-token requests reuse the accepted record; a different message needs a fresh page/token.

Submissions and a recipient snapshot in the notification outbox are saved transactionally before queue dispatch. Queue failure or SMTP failure retains the enquiry. Configure an asynchronous durable queue (`database` by default), install the host's queue schema, and run its worker on the `site-builder-forms` queue. Configure an appropriate worker/transport timeout below the outbox's ten-minute processing lease; keep the queue retry_after above the job timeout (120 seconds). Schedule `site-builder:forms-dispatch` every minute to recover due/expired work, and `site-builder:forms-prune` daily. Automatic notification retries stop after five attempts; admins may explicitly retry. Set notifications_enabled=false to retain inbox-only acceptance while pausing delivery.

```php
use Illuminate\Support\Facades\Schedule;
Schedule::command('site-builder:forms-dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('site-builder:forms-prune')->daily();
```

```sh
php artisan queue:work database --queue=site-builder-forms --timeout=120
php artisan site-builder:forms-doctor --json
php artisan site-builder:forms-prune --dry-run
```

Readiness checks do not prove a worker is running or SMTP reaches the recipient. A status of sent means handed to the configured transport. Delivery is at least once: a crash after SMTP acceptance but before recording success can duplicate an email. Verify your real transport separately using authorized test recipients. Package tests use fake/local mail only.

Retention defaults to 90 days from submission creation and removes corresponding notification work too; customize retention_days to the host's policy, review `--dry-run`, and schedule deliberately. Protect database/backups and restrict inbox access. Restore code, database and media coherently. Rollback after authoring new blocks/forms requires the pre-upgrade backup; older packages cannot interpret all new content. No live consumer upgrade is performed by publishing these packages.
