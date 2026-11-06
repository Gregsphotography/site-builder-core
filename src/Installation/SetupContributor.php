<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

interface SetupContributor
{
    public function id(): string;

    public function discover(): SetupDiscovery;

    public function plan(SetupAnswers $answers, SetupDiscovery $discovery): SetupContribution;

    /** @return list<DoctorCheck> */
    public function checks(SetupAnswers $answers, SetupDiscovery $discovery): array;
}
