<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use SiteBuilder\Core\Installation\SetupAnswers;
use SiteBuilder\Core\Installation\SetupAuthorization;
use SiteBuilder\Core\Installation\SetupFailure;
use SiteBuilder\Core\Installation\SetupPagePolicy;
use SiteBuilder\Core\Installation\SetupRegistry;
use SiteBuilder\Core\Installation\SetupRuntime;
use SiteBuilder\Core\Installation\SetupSitePartPolicy;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\SavedSections\SavedSection;
use SiteBuilder\Core\SiteParts\SitePart;
use SiteBuilder\Core\Tests\DatabaseTestCase;

class SetupAuthorizationTest extends DatabaseTestCase
{
    private SetupActor $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('site_builder_setup_actors', function ($table): void {
            $table->string('id')->primary();
        });
        $this->actor = SetupActor::query()->create(['id' => '00000000-0000-4000-8000-000000000099']);
        config(['auth.guards.staff' => ['driver' => 'session', 'provider' => 'setup-staff'], 'auth.providers.setup-staff' => ['driver' => 'eloquent', 'model' => SetupActor::class]]);
        Gate::policy(Page::class, SetupPagePolicy::class);
        Gate::policy(SitePart::class, SetupSitePartPolicy::class);
    }

    private function configure(): \stdClass
    {
        $value = json_decode(file_get_contents(__DIR__.'/../Fixtures/setup-uuid.json'));
        $value->authorization->pageAbilities = ['view', 'create', 'update', 'preview'];
        $value->authorization->sitePartAbilities = (object) ['header' => ['view', 'use'], 'site-design' => ['view']];
        $value->authorization->mediaAbilities = [];
        new SetupAnswers($value);
        config(['site-builder-setup.answers' => $value]);
        app(SetupRuntime::class)->boot();

        return $value;
    }

    public function test_uuid_actor_has_only_selected_page_rights_and_no_implicit_media_saved_or_import_rights(): void
    {
        $this->configure();
        $gate = Gate::forUser($this->actor);
        $page = new Page;
        $this->assertTrue($gate->allows('create', Page::class));
        $this->assertTrue($gate->allows('update', $page));
        $this->assertFalse($gate->allows('publish', $page));
        $this->assertFalse($gate->allows('restore', $page));
        $this->assertFalse($gate->allows('delete', $page));
        $this->assertFalse($gate->allows('import', Page::class));
        $this->assertFalse($gate->allows('create', SavedSection::class));
        $this->assertFalse(app(SetupAuthorization::class)->media($this->actor, 'view'));
        $this->assertFalse(app(SetupAuthorization::class)->media($this->actor, 'upload'));
        $other = SetupActor::query()->create(['id' => '00000000-0000-4000-8000-000000000098']);
        $this->assertFalse(Gate::forUser($other)->allows('create', Page::class));
        $impostor = new SetupOtherActor(['id' => $this->actor->id]);
        $this->assertFalse(Gate::forUser($impostor)->allows('create', Page::class));
        $this->assertSame($this->actor->id, app(SetupRegistry::class)->selectedActors(new SetupAnswers(config('site-builder-setup.answers')))[0]->id);
    }

    public function test_site_part_kind_use_publish_and_withdraw_are_independent(): void
    {
        $this->configure();
        $gate = Gate::forUser($this->actor);
        $header = (new SitePart)->forceFill(['kind' => 'header']);
        $section = (new SitePart)->forceFill(['kind' => 'section']);
        $design = (new SitePart)->forceFill(['kind' => 'design']);
        $this->assertTrue($gate->allows('viewAny', SitePart::class));
        $this->assertTrue($gate->allows('use', $header));
        $this->assertFalse($gate->allows('update', $header));
        $this->assertFalse($gate->allows('publish', $header));
        $this->assertFalse($gate->allows('withdraw', $header));
        $this->assertFalse($gate->allows('use', $section));
        $this->assertTrue($gate->allows('view', $design));
        $this->assertFalse($gate->allows('use', $design));
        $this->assertFalse($gate->allows('create', [SitePart::class, 'header']));
    }

    public function test_grants_fail_closed_before_runtime_registration_and_in_deny_mode(): void
    {
        $this->assertFalse(Gate::forUser($this->actor)->allows('create', Page::class));
        $value = $this->configure();
        $value->authorization = (object) ['mode' => 'deny', 'guard' => 'staff', 'actorAlias' => null, 'actorKeys' => [], 'pageAbilities' => [], 'sitePartAbilities' => (object) [], 'mediaAbilities' => []];
        config(['site-builder-setup.answers' => $value]);
        $this->assertFalse(Gate::forUser($this->actor)->allows('create', Page::class));
        $this->assertFalse(app(SetupAuthorization::class)->media($this->actor, 'upload'));
    }

    public function test_http_authorization_requires_the_selected_guard_and_same_actor(): void
    {
        $this->configure();
        $property = new \ReflectionProperty(app(), 'isRunningInConsole');
        $original = $property->getValue(app());
        $property->setValue(app(), false);
        try {
            $this->assertFalse(Gate::forUser($this->actor)->allows('create', Page::class));
            Auth::guard('web')->setUser($this->actor);
            $this->assertFalse(Gate::forUser($this->actor)->allows('create', Page::class));
            Auth::guard('staff')->setUser($this->actor);
            $this->assertTrue(Gate::forUser($this->actor)->allows('create', Page::class));
        } finally {
            $property->setValue(app(), $original);
        }
    }

    public function test_existing_host_policy_is_preserved_and_never_replaced_by_allowlist_boot(): void
    {
        Gate::policy(Page::class, SetupHostPolicy::class);
        try {
            $this->configure();
            $this->fail('Existing policy replaced.');
        } catch (SetupFailure $failure) {
            $this->assertSame('policy-conflict', $failure->reason);
        }
        $this->assertInstanceOf(SetupHostPolicy::class, Gate::getPolicyFor(Page::class));
    }
}

class SetupActor extends User
{
    protected $table = 'site_builder_setup_actors';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;
}

class SetupOtherActor extends SetupActor {}

class SetupHostPolicy
{
    public function create($actor): bool
    {
        return false;
    }
}
