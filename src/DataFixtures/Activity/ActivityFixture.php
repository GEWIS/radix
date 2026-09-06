<?php

declare(strict_types=1);

namespace App\DataFixtures\Activity;

use App\DataFixtures\Career\CompanyFixture;
use App\DataFixtures\Decision\ProjectionReferenceFixture;
use App\DataFixtures\User\UserFixture;
use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityLabel;
use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\ActivityRevisionComment;
use App\Entity\Activity\Enums\ActivityCategories;
use App\Entity\Activity\Enums\AllocationMethod;
use App\Entity\Activity\Enums\CohortTier;
use App\Entity\Activity\Enums\DrawCutoffRule;
use App\Entity\Activity\Enums\MembershipPriorityMode;
use App\Entity\Activity\Enums\MembershipTier;
use App\Entity\Activity\Enums\SignupFieldTypes;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\Signup;
use App\Entity\Activity\SignupField;
use App\Entity\Activity\SignupFieldValue;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\SignupOption;
use App\Entity\Activity\SignupRole;
use App\Entity\Activity\UserSignup;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Career\Company;
use App\Entity\Database\Enums\ProgramType;
use App\Entity\Decision\Member;
use App\Entity\Decision\Organ;
use App\Entity\User\User;
use App\Service\Activity\AdmissionOrder;
use DateTime;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Override;

use function array_map;
use function assert;
use function count;
use function in_array;
use function is_array;
use function sprintf;

/**
 * @phpstan-type SignupListSeedType = array{
 *     name: array{en: string, nl: string},
 *     openDate: string,
 *     closeDate: string,
 *     onlyGEWIS: bool,
 *     displaySubscribedNumber: bool,
 *     limitedCapacity: bool,
 *     capacity?: int,
 *     allocationMethod?: AllocationMethod,
 *     drawCutoffRule?: DrawCutoffRule,
 *     drawCutoffAt?: string,
 *     drawAfterDurationHours?: int,
 *     externalPolicyUrl?: string,
 *     customMethodDescription?: string,
 *     draw?: bool,
 *     drawnAt?: string,
 *     drawnBy?: int,
 *     membershipTierOrder?: list<list<MembershipTier>>,
 *     membershipPriorityMode?: MembershipPriorityMode,
 *     membershipPlaces?: array<string, int>,
 *     cohortTierOrder?: list<list<CohortTier>>,
 *     programTypeOrder?: list<list<ProgramType>>,
 *     organisingCommitteePlaces?: int,
 *     roles?: list<array{name: string, minimum: int}>,
 *     promoted?: bool,
 *     presenceTaken?: bool,
 *     fields?: list<array<string, mixed>>,
 *     subscribers?: list<int|array<string, mixed>>,
 *     externals?: list<array<string, mixed>>,
 * }
 * @phpstan-type ActivitySeedType = array{
 *     creator: int,
 *     status: RevisionStatus,
 *     beginTime: string,
 *     endTime: string,
 *     category: ActivityCategories,
 *     requireGEFLITST: bool,
 *     requireZettle: bool,
 *     name: array{en: string, nl: string},
 *     location: array{en: string, nl: string},
 *     costs: array{en: string, nl: string},
 *     description: array{en: string, nl: string},
 *     labels?: list<string>,
 *     company?: string,
 *     cancelled?: bool,
 *     unpublished?: bool,
 *     organ?: string,
 *     signupLists?: list<SignupListSeedType>,
 * }
 */
class ActivityFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    /** @var list<array{Signup, DateTime}> the sign-ups whose moment is written once they have an id */
    private array $signedUpAt = [];

    #[Override]
    public function load(ObjectManager $manager): void
    {
        // Ordered chronologically by begin time so the rows are inserted (and auto-incremented) in that order.
        /** @var list<ActivitySeedType> $activities */
        $activities = [
            // Past, two association years ago (AY 2023-2024): appears under that heading in the archive and
            // exercises the same-day, past-year date format.
            [
                'creator' => 8020,
                'status' => RevisionStatus::Approved,
                'beginTime' => '2024-02-20 19:00',
                'endTime' => '2024-02-20 22:00',
                'category' => ActivityCategories::Cultural,
                'requireGEFLITST' => false,
                'requireZettle' => false,
                'name' => [
                    'en' => 'Museum Visit',
                    'nl' => 'Museumbezoek',
                ],
                'location' => [
                    'en' => 'Van Abbemuseum',
                    'nl' => 'Van Abbemuseum',
                ],
                'costs' => [
                    'en' => '5 euro',
                    'nl' => '5 euro',
                ],
                'description' => [
                    'en' => 'A guided evening tour of the modern art collection.',
                    'nl' => 'Een rondleiding langs de moderne kunstcollectie.',
                ],
            ],
            // Past, multi-day, in a previous calendar year (fixed dates): exercises the multi-day + year date format.
            [
                'creator' => 8024,
                'status' => RevisionStatus::Approved,
                'beginTime' => '2025-12-12 17:00',
                'endTime' => '2025-12-14 12:00',
                'category' => ActivityCategories::Weekend,
                'requireGEFLITST' => false,
                'requireZettle' => false,
                'name' => [
                    'en' => 'Winter Weekend',
                    'nl' => 'Winterweekend',
                ],
                'location' => [
                    'en' => 'Ardennes',
                    'nl' => 'Ardennen',
                ],
                'costs' => [
                    'en' => '75 euro',
                    'nl' => '75 euro',
                ],
                'description' => [
                    'en' => 'A three-day winter getaway in the Ardennes with hikes, board games, and plenty of '
                        . '**hot chocolate**. Cabins are shared; transport is arranged together by carpool.',
                    'nl' => 'Een driedaags winterweekend in de Ardennen met wandelingen, bordspellen en volop '
                        . '**warme chocolademelk**. De huisjes worden gedeeld; vervoer regelen we samen via carpool.',
                ],
                'labels' => [
                    ActivityLabelFixture::REFERENCE_DUTCH_ONLY,
                ],
                'signupLists' => [
                    [
                        'name' => [
                            'en' => 'Attendance',
                            'nl' => 'Aanwezigheid',
                        ],
                        'openDate' => '2025-11-01 12:00',
                        'closeDate' => '2025-12-01 12:00',
                        'onlyGEWIS' => true,
                        'displaySubscribedNumber' => true,
                        'limitedCapacity' => true,
                        'presenceTaken' => true,
                    ],
                ],
            ],
            // Past (already happened), approved, organised by a discharged member.
            [
                'creator' => 8021,
                'status' => RevisionStatus::Approved,
                'beginTime' => '-2 months 20:00',
                'endTime' => '-2 months 23:30',
                'category' => ActivityCategories::Recreational,
                'requireGEFLITST' => false,
                'requireZettle' => false,
                'name' => [
                    'en' => 'Movie Night',
                    'nl' => 'Filmavond',
                ],
                'location' => [
                    'en' => 'Common Room',
                    'nl' => 'Huiskamer',
                ],
                'costs' => [
                    'en' => 'Free',
                    'nl' => 'Gratis',
                ],
                'description' => [
                    'en' => "Grab a place and a blanket for a cosy **movie night** at the association.\n\n"
                        . 'We screen two films back to back, with a short break for free popcorn and drinks in '
                        . "between. The theme changes every month and is decided by a poll among members.\n\n"
                        . 'No sign-up needed for the second film — just show up. Expect the evening to run until '
                        . 'well past midnight, so bring something comfortable to sit on.',
                    'nl' => "Pak een stoel en een dekentje voor een gezellige **filmavond** bij de vereniging.\n\n"
                        . 'We vertonen twee films achter elkaar, met een korte pauze voor gratis popcorn en drankjes '
                        . "ertussen. Het thema wisselt elke maand en wordt bepaald via een poll onder leden.\n\n"
                        . 'Voor de tweede film is geen aanmelding nodig — kom gewoon langs. De avond loopt door tot '
                        . 'ruim na middernacht, dus neem iets comfortabels mee om op te zitten.',
                ],
                'labels' => [
                    ActivityLabelFixture::REFERENCE_DUTCH_ONLY,
                    ActivityLabelFixture::REFERENCE_EXTERNALS,
                ],
                'signupLists' => [
                    [
                        'name' => [
                            'en' => 'Attendance',
                            'nl' => 'Aanwezigheid',
                        ],
                        'openDate' => '-3 months 12:00',
                        'closeDate' => '-2 months 18:00',
                        'onlyGEWIS' => true,
                        'displaySubscribedNumber' => true,
                        'limitedCapacity' => false,
                        'presenceTaken' => true,
                    ],
                ],
            ],
            // Past (already happened), approved, organised by a discharged member.
            // Closed signup list with presence taken.
            [
                'creator' => 8023,
                'status' => RevisionStatus::Approved,
                'beginTime' => '-3 weeks 19:30',
                'endTime' => '-3 weeks 22:00',
                'category' => ActivityCategories::Workshop,
                'requireGEFLITST' => false,
                'requireZettle' => false,
                'name' => [
                    'en' => 'Workshop',
                    'nl' => 'Workshop',
                ],
                'location' => [
                    'en' => 'Room 2',
                    'nl' => 'Zaal 2',
                ],
                'costs' => [
                    'en' => '5 euro',
                    'nl' => '5 euro',
                ],
                'description' => [
                    'en' => 'A hands-on workshop.',
                    'nl' => 'Een praktische workshop.',
                ],
                'labels' => [
                    ActivityLabelFixture::REFERENCE_MASTER,
                    ActivityLabelFixture::REFERENCE_ENGLISH_ONLY,
                ],
                'signupLists' => [
                    [
                        'name' => [
                            'en' => 'Participants',
                            'nl' => 'Deelnemers',
                        ],
                        'openDate' => '-6 weeks 12:00',
                        'closeDate' => '-4 weeks 12:00',
                        'onlyGEWIS' => false,
                        'displaySubscribedNumber' => true,
                        'limitedCapacity' => true,
                        'capacity' => 3,
                        'allocationMethod' => AllocationMethod::ConditionalDraw,
                        'drawCutoffRule' => DrawCutoffRule::OnClose,
                        'drawnAt' => '-4 weeks 12:00',
                        'drawnBy' => 8025,
                        'presenceTaken' => true,
                        'membershipTierOrder' => [
                            [
                                MembershipTier::Ordinary,
                                MembershipTier::External,
                                MembershipTier::Honorary,
                                MembershipTier::Graduate,
                            ],
                            [MembershipTier::NonMember],
                        ],
                        'membershipPriorityMode' => MembershipPriorityMode::Ordering,
                        // A closed, past, limited-capacity list exercising the full flow: 3 admitted (capacity 3) of
                        // whom 2 attended and 1 was a no-show, plus 2 on the waiting list (one a non-member external),
                        // an obvious backfill opportunity. Also covers extra fields and mixed membership types.
                        'fields' => [
                            [
                                'type' => SignupFieldTypes::Text,
                                'name' => [
                                    'en' => 'Dietary requirements',
                                    'nl' => 'Dieetwensen',
                                ],
                            ],
                            [
                                'type' => SignupFieldTypes::Choice,
                                'name' => [
                                    'en' => 'T-shirt size',
                                    'nl' => 'T-shirtmaat',
                                ],
                                'options' => [
                                    [
                                        'en' => 'S',
                                        'nl' => 'S',
                                    ],
                                    [
                                        'en' => 'M',
                                        'nl' => 'M',
                                        'default' => true,
                                    ],
                                    [
                                        'en' => 'L',
                                        'nl' => 'L',
                                    ],
                                ],
                            ],
                        ],
                        'subscribers' => [
                            [
                                'member' => 8005, // ordinary: admitted, attended
                                'drawn' => true,
                                'present' => true,
                                'answers' => [
                                    'Dietary requirements' => 'Vegetarian',
                                    'T-shirt size' => 'M',
                                ],
                            ],
                            [
                                'member' => 8006, // ordinary: admitted, attended
                                'drawn' => true,
                                'present' => true,
                                'answers' => ['T-shirt size' => 'L'],
                            ],
                            [
                                'member' => 8015, // external member: admitted, no-show
                                'drawn' => true,
                                'present' => false,
                                'answers' => [
                                    'Dietary requirements' => 'None',
                                    'T-shirt size' => 'S',
                                ],
                            ],
                            [
                                'member' => 8155, // graduate: waiting list
                                'drawn' => false,
                                'present' => false,
                                'answers' => ['T-shirt size' => 'M'],
                            ],
                        ],
                        'externals' => [
                            [
                                'fullName' => 'Alex Visitor', // non-member: waiting list
                                'email' => 'alex.visitor@example.org',
                                'drawn' => false,
                                'present' => false,
                                'answers' => [
                                    'Dietary requirements' => 'Gluten-free',
                                    'T-shirt size' => 'L',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            // Ongoing right now (began earlier, ends later): exercises the "ongoing for %duration%" note. A drop-in
            // borrel has no sign-up: a sign-up list can never still be open once the activity has started.
            [
                'creator' => 8012,
                'status' => RevisionStatus::Approved,
                'beginTime' => '-1 hour',
                'endTime' => '+3 hours',
                'category' => ActivityCategories::SocialDrink,
                'requireGEFLITST' => false,
                'requireZettle' => false,
                'name' => [
                    'en' => 'Open Borrel',
                    'nl' => 'Open Borrel',
                ],
                'location' => [
                    'en' => 'Association Room',
                    'nl' => 'Verenigingskamer',
                ],
                'costs' => [
                    'en' => 'Free',
                    'nl' => 'Gratis',
                ],
                'description' => [
                    'en' => 'Drop by the association room for a drink — we are open right now.',
                    'nl' => 'Kom langs in de verenigingskamer voor een drankje — we zijn nu open.',
                ],
            ],
            // Upcoming, with a sign-up that closes within a day: exercises the imminent-deadline colour (text-danger)
            // on an activity that has not started yet, so its sign-up can legitimately still be open.
            [
                'creator' => 8013,
                'status' => RevisionStatus::Approved,
                'beginTime' => '+2 days 20:00',
                'company' => 'nexunt',
                'endTime' => '+2 days 23:00',
                'category' => ActivityCategories::Recreational,
                'requireGEFLITST' => false,
                'requireZettle' => false,
                'name' => [
                    'en' => 'Pub Quiz',
                    'nl' => 'Pubquiz',
                ],
                'location' => [
                    'en' => 'Common Room',
                    'nl' => 'Huiskamer',
                ],
                'costs' => [
                    'en' => '2 euro',
                    'nl' => '2 euro',
                ],
                'description' => [
                    'en' => 'Test your trivia knowledge in teams. Sign up soon — registration closes within a day!',
                    'nl' => 'Test je kennis in teams. Schrijf je snel in — de inschrijving sluit binnen een dag!',
                ],
                'signupLists' => [
                    [
                        'name' => [
                            'en' => 'Teams',
                            'nl' => 'Teams',
                        ],
                        'openDate' => '-2 days 12:00',
                        'closeDate' => '+12 hours',
                        'onlyGEWIS' => true,
                        'displaySubscribedNumber' => true,
                        'limitedCapacity' => true,
                    ],
                ],
            ],
            // Upcoming, approved, organised by a board member.
            [
                'creator' => 8025,
                'status' => RevisionStatus::Approved,
                'beginTime' => '+2 weeks 19:00',
                'company' => 'nexunt',
                'endTime' => '+2 weeks 23:00',
                'category' => ActivityCategories::SocialDrink,
                'requireGEFLITST' => false,
                'requireZettle' => false,
                'name' => [
                    'en' => 'Monthly Drink',
                    'nl' => 'Maandelijkse Borrel',
                ],
                'location' => [
                    'en' => 'Association Room',
                    'nl' => 'Verenigingskamer',
                ],
                'costs' => [
                    'en' => 'Free',
                    'nl' => 'Gratis',
                ],
                'description' => [
                    'en' => 'Join us for the monthly drink.',
                    'nl' => 'Kom langs op de maandelijkse borrel.',
                ],
                'signupLists' => [
                    [
                        'name' => [
                            'en' => 'Attendance',
                            'nl' => 'Aanwezigheid',
                        ],
                        'openDate' => '-2 days 12:00',
                        'closeDate' => '+4 days 18:00',
                        'onlyGEWIS' => true,
                        'displaySubscribedNumber' => true,
                        'limitedCapacity' => false,
                    ],
                ],
            ],
            // Upcoming, awaiting approval, organised by an active (external) member.
            [
                'creator' => 8017,
                'status' => RevisionStatus::Submitted,
                'beginTime' => '+3 weeks 12:30',
                'endTime' => '+3 weeks 14:00',
                'category' => ActivityCategories::Education,
                'requireGEFLITST' => false,
                'requireZettle' => false,
                'name' => [
                    'en' => 'Lunch Lecture',
                    'nl' => 'Lunchlezing',
                ],
                'location' => [
                    'en' => 'Lecture Hall 1',
                    'nl' => 'Collegezaal 1',
                ],
                'costs' => [
                    'en' => 'Free',
                    'nl' => 'Gratis',
                ],
                'description' => [
                    'en' => 'A lunch lecture by an industry guest.',
                    'nl' => 'Een lunchlezing door een gast uit het bedrijfsleven.',
                ],
                'labels' => [
                    ActivityLabelFixture::REFERENCE_FIRST_YEAR,
                    ActivityLabelFixture::REFERENCE_ENGLISH_ONLY,
                ],
            ],
            // Upcoming, approved, organised by an active member, needs photographer + payment terminal.
            // Has a single, currently open signup list with limited capacity.
            [
                'creator' => 8010,
                'status' => RevisionStatus::Approved,
                'beginTime' => '+1 month 17:00',
                'company' => 'nexunt',
                'endTime' => '+1 month 22:00',
                'category' => ActivityCategories::Party,
                'requireGEFLITST' => true,
                'requireZettle' => true,
                'name' => [
                    'en' => 'Gala',
                    'nl' => 'Gala',
                ],
                'location' => [
                    'en' => 'City Hall',
                    'nl' => 'Stadhuis',
                ],
                'costs' => [
                    'en' => '25 euro',
                    'nl' => '25 euro',
                ],
                'description' => [
                    'en' => 'The annual gala dinner.',
                    'nl' => 'Het jaarlijkse galadiner.',
                ],
                'signupLists' => [
                    [
                        'name' => [
                            'en' => 'Attendance',
                            'nl' => 'Aanwezigheid',
                        ],
                        'openDate' => '-1 week 12:00',
                        'closeDate' => '+3 weeks 12:00',
                        'onlyGEWIS' => true,
                        'displaySubscribedNumber' => true,
                        'limitedCapacity' => true,
                        // Upcoming limited list whose draw has not happened yet (sign-up still open, so the draw is
                        // blocked): all 4 sign-ups default to the waiting list, capacity 2 → "Admitted: 0 / 2".
                        'capacity' => 2,
                        'allocationMethod' => AllocationMethod::ConditionalDraw,
                        'drawCutoffRule' => DrawCutoffRule::OnClose,
                        'subscribers' => [
                            8005,
                            8006,
                            8007,
                            8008,
                        ],
                    ],
                ],
            ],
            // Upcoming, approved, organised by a board member. Two signup lists, one promoted.
            [
                'creator' => 8026,
                'status' => RevisionStatus::Approved,
                'beginTime' => '+5 weeks 18:00',
                'company' => 'nexunt',
                'endTime' => '+5 weeks 23:59',
                'category' => ActivityCategories::Conference,
                'requireGEFLITST' => true,
                'requireZettle' => true,
                'name' => [
                    'en' => 'Symposium',
                    'nl' => 'Symposium',
                ],
                'location' => [
                    'en' => 'Auditorium',
                    'nl' => 'Auditorium',
                ],
                'costs' => [
                    'en' => 'Free',
                    'nl' => 'Gratis',
                ],
                'description' => [
                    'en' => "## Programme\n\n"
                        . 'The annual **GEWIS Symposium** brings together students, alumni, and companies for a full '
                        . "day of talks on the latest in computer science and mathematics.\n\n"
                        . "The day is split into several tracks:\n\n"
                        . "- Morning keynotes by leading researchers\n"
                        . "- Hands-on afternoon workshops\n"
                        . "- An evening dinner with drinks to close the day\n\n"
                        . 'Whether you are a first-year or a seasoned PhD candidate, there is something for everyone. '
                        . 'Doors open at 09:00; check the [programme booklet](https://gewis.nl) for the full schedule '
                        . 'and come prepared to learn something new.',
                    'nl' => "## Programma\n\n"
                        . 'Het jaarlijkse **GEWIS Symposium** brengt studenten, alumni en bedrijven samen voor een dag '
                        . "vol lezingen over de nieuwste ontwikkelingen in informatica en wiskunde.\n\n"
                        . "De dag bestaat uit verschillende tracks:\n\n"
                        . "- Ochtendkeynotes door vooraanstaande onderzoekers\n"
                        . "- Praktische workshops in de middag\n"
                        . "- Een diner met borrel als afsluiting\n\n"
                        . 'Of je nu eerstejaars of een ervaren promovendus bent, er is voor ieder wat wils. '
                        . 'De deuren openen om 09:00; bekijk het [programmaboekje](https://gewis.nl) voor het '
                        . 'volledige schema en kom klaar om iets nieuws te leren.',
                ],
                'labels' => [
                    ActivityLabelFixture::REFERENCE_MASTER,
                    ActivityLabelFixture::REFERENCE_PHD,
                    ActivityLabelFixture::REFERENCE_ENGLISH_ONLY,
                ],
                'signupLists' => [
                    [
                        'name' => [
                            'en' => 'Talks',
                            'nl' => 'Lezingen',
                        ],
                        'openDate' => '-1 week 12:00',
                        'closeDate' => '+4 weeks 12:00',
                        'onlyGEWIS' => false,
                        'displaySubscribedNumber' => true,
                        'limitedCapacity' => false,
                        'promoted' => true,
                    ],
                    [
                        'name' => [
                            'en' => 'Dinner',
                            'nl' => 'Diner',
                        ],
                        'openDate' => '-1 week 12:00',
                        'closeDate' => '+3 weeks 12:00',
                        'onlyGEWIS' => true,
                        'displaySubscribedNumber' => false,
                        'limitedCapacity' => true,
                        'capacity' => 40,
                        // An external party (the venue) allocates the places; admission is recorded by hand.
                        'allocationMethod' => AllocationMethod::ExternalParty,
                        'externalPolicyUrl' => 'https://example.org/venue-policy',
                    ],
                ],
            ],
            // Upcoming career activity, approved, organised by an active (external) member.
            // Signup list open to non-GEWIS members.
            [
                'creator' => 8018,
                'status' => RevisionStatus::Approved,
                'beginTime' => '+6 weeks 13:00',
                'endTime' => '+6 weeks 17:00',
                'category' => ActivityCategories::Career,
                'requireGEFLITST' => false,
                'requireZettle' => false,
                'name' => [
                    'en' => 'Career Day',
                    'nl' => 'Carrièredag',
                ],
                'location' => [
                    'en' => 'Atlas Building',
                    'nl' => 'Atlasgebouw',
                ],
                'costs' => [
                    'en' => 'Free',
                    'nl' => 'Gratis',
                ],
                'description' => [
                    'en' => "Curious about life **after** your studies? The Career Day is the place to be.\n\n"
                        . 'Dozens of companies — from fast-growing start-ups to established multinationals — will be '
                        . "present to tell you about internships, graduation projects, and full-time positions.\n\n"
                        . "What to expect:\n\n"
                        . "- One-on-one chats at company stands\n"
                        . "- Short company pitches throughout the day\n"
                        . "- Free lunch and an informal closing drink\n\n"
                        . 'Bring a few copies of your CV and dress to impress. Pre-registration is appreciated but '
                        . 'walk-ins are always welcome.',
                    'nl' => "Benieuwd naar het leven **na** je studie? Dan is de Carrièredag dé plek om te zijn.\n\n"
                        . 'Tientallen bedrijven — van snelgroeiende start-ups tot gevestigde multinationals — zijn '
                        . "aanwezig om te vertellen over stages, afstudeerprojecten en vaste banen.\n\n"
                        . "Wat je kunt verwachten:\n\n"
                        . "- Persoonlijke gesprekken bij bedrijfsstands\n"
                        . "- Korte bedrijfspitches gedurende de dag\n"
                        . "- Gratis lunch en een informele afsluitende borrel\n\n"
                        . 'Neem een paar exemplaren van je cv mee en kleed je netjes. Aanmelden vooraf wordt '
                        . 'gewaardeerd, maar je bent ook zonder aanmelding van harte welkom.',
                ],
                'labels' => [
                    ActivityLabelFixture::REFERENCE_FIRST_YEAR,
                    ActivityLabelFixture::REFERENCE_BACHELOR,
                    ActivityLabelFixture::REFERENCE_MASTER,
                ],
                'signupLists' => [
                    [
                        'name' => [
                            'en' => 'Registration',
                            'nl' => 'Registratie',
                        ],
                        'openDate' => '-3 days 12:00',
                        'closeDate' => '+5 weeks 12:00',
                        'onlyGEWIS' => false,
                        'displaySubscribedNumber' => true,
                        'limitedCapacity' => false,
                    ],
                ],
            ],
            // Upcoming, multi-day (spans several days): exercises the multi-day date format for future activities.
            [
                'creator' => 8027,
                'status' => RevisionStatus::Approved,
                'beginTime' => '+7 weeks 09:00',
                'endTime' => '+7 weeks +2 days 17:00',
                'category' => ActivityCategories::Conference,
                'requireGEFLITST' => true,
                'requireZettle' => true,
                'name' => [
                    'en' => 'Study Conference',
                    'nl' => 'Studiecongres',
                ],
                'location' => [
                    'en' => 'Conference Centre',
                    'nl' => 'Congrescentrum',
                ],
                'costs' => [
                    'en' => '40 euro',
                    'nl' => '40 euro',
                ],
                'description' => [
                    'en' => 'A three-day conference packed with lectures, workshops, and excursions. Accommodation '
                        . 'and most meals are included; have a look at the schedule before you sign up.',
                    'nl' => 'Een driedaags congres vol lezingen, workshops en excursies. Overnachting en de meeste '
                        . 'maaltijden zijn inbegrepen; bekijk het programma voordat je je inschrijft.',
                ],
                'labels' => [
                    ActivityLabelFixture::REFERENCE_MASTER,
                    ActivityLabelFixture::REFERENCE_PHD,
                ],
                'signupLists' => [
                    [
                        'name' => [
                            'en' => 'Attendance',
                            'nl' => 'Aanwezigheid',
                        ],
                        'openDate' => '-1 week 12:00',
                        'closeDate' => '+5 weeks 12:00',
                        'onlyGEWIS' => true,
                        'displaySubscribedNumber' => true,
                        'limitedCapacity' => true,
                        'capacity' => 30,
                        // A bespoke selection (study-phase mix); admission is recorded by hand.
                        'allocationMethod' => AllocationMethod::Custom,
                        'customMethodDescription' => 'Selected to balance bachelor, master and PhD attendees.',
                    ],
                ],
            ],
            // Upcoming, approved, board-organised, with a CLOSED limited sign-up list, so the draw is testable
            // end-to-end (sign-up over, activity still in the future, more sign-ups than places, not yet drawn).
            // NOTE: this list is due for the automated draw the moment it is seeded, so in dev a running scheduler
            // draws it within a minute; re-seed to demo the pre-draw state or the manual board fallback.
            [
                'creator' => 8025,
                'status' => RevisionStatus::Approved,
                'beginTime' => '+10 days 19:00',
                'endTime' => '+10 days 23:00',
                'category' => ActivityCategories::Recreational,
                'requireGEFLITST' => false,
                'requireZettle' => false,
                'name' => [
                    'en' => 'Excursion',
                    'nl' => 'Excursie',
                ],
                'location' => [
                    'en' => 'Brewery',
                    'nl' => 'Brouwerij',
                ],
                'costs' => [
                    'en' => '15 euro',
                    'nl' => '15 euro',
                ],
                'description' => [
                    'en' => 'A guided brewery tour with limited places.',
                    'nl' => 'Een rondleiding door een brouwerij met beperkt aantal plaatsen.',
                ],
                'signupLists' => [
                    [
                        'name' => [
                            'en' => 'Attendance',
                            'nl' => 'Aanwezigheid',
                        ],
                        'openDate' => '-2 weeks 12:00',
                        'closeDate' => '-1 day 12:00',
                        'onlyGEWIS' => true,
                        'displaySubscribedNumber' => true,
                        'limitedCapacity' => true,
                        'capacity' => 2,
                        'allocationMethod' => AllocationMethod::ConditionalDraw,
                        'drawCutoffRule' => DrawCutoffRule::OnClose,
                        'subscribers' => [
                            8005,
                            8006,
                            8007,
                            8008,
                        ],
                    ],
                ],
            ],
            // Upcoming, approved, but CANCELLED by the board: it stays publicly visible with a [CANCELLED] marker and a
            // notice, and all sign-up interaction is frozen (existing sign-ups are kept, but nobody can join/leave).
            [
                'creator' => 8025,
                'status' => RevisionStatus::Approved,
                'cancelled' => true,
                'beginTime' => '+3 weeks 20:00',
                'endTime' => '+3 weeks 23:59',
                'category' => ActivityCategories::SocialDrink,
                'requireGEFLITST' => false,
                'requireZettle' => false,
                'name' => [
                    'en' => 'Cancelled Gala',
                    'nl' => 'Geannuleerd Gala',
                ],
                'location' => [
                    'en' => 'Grand Hall',
                    'nl' => 'Grote Zaal',
                ],
                'costs' => [
                    'en' => '10 euro',
                    'nl' => '10 euro',
                ],
                'description' => [
                    'en' => 'This gala has unfortunately been cancelled.',
                    'nl' => 'Dit gala is helaas geannuleerd.',
                ],
                'signupLists' => [
                    [
                        'name' => [
                            'en' => 'Attendance',
                            'nl' => 'Aanwezigheid',
                        ],
                        'openDate' => '-2 days 12:00',
                        'closeDate' => '+1 week 18:00',
                        'onlyGEWIS' => true,
                        'displaySubscribedNumber' => true,
                        'limitedCapacity' => false,
                        'subscribers' => [
                            8005,
                            8006,
                        ],
                    ],
                ],
            ],
            // Upcoming, approved, but UNPUBLISHED by the board: it is removed from public view entirely (listings,
            // calendar, and a 404 on its direct URL) and all sign-up interaction is frozen, but it can be re-published.
            [
                'creator' => 8025,
                'status' => RevisionStatus::Approved,
                'unpublished' => true,
                'beginTime' => '+4 weeks 19:00',
                'endTime' => '+4 weeks 22:00',
                'category' => ActivityCategories::Other,
                'requireGEFLITST' => false,
                'requireZettle' => false,
                'name' => [
                    'en' => 'Unpublished Workshop',
                    'nl' => 'Gedepubliceerde Workshop',
                ],
                'location' => [
                    'en' => 'Lecture Room',
                    'nl' => 'Collegezaal',
                ],
                'costs' => [
                    'en' => 'Free',
                    'nl' => 'Gratis',
                ],
                'description' => [
                    'en' => 'This workshop is being reworked and is temporarily not public.',
                    'nl' => 'Deze workshop wordt herzien en is tijdelijk niet openbaar.',
                ],
                'signupLists' => [
                    [
                        'name' => [
                            'en' => 'Attendance',
                            'nl' => 'Aanwezigheid',
                        ],
                        'openDate' => '-1 day 12:00',
                        'closeDate' => '+2 weeks 18:00',
                        'onlyGEWIS' => true,
                        'displaySubscribedNumber' => true,
                        'limitedCapacity' => false,
                        'subscribers' => [
                            8007,
                        ],
                    ],
                ],
            ],
            [
                'creator' => 8025,
                'status' => RevisionStatus::Approved,
                'beginTime' => '+12 days 09:00',
                'endTime' => '+12 days 18:00',
                'category' => ActivityCategories::Recreational,
                'requireGEFLITST' => false,
                'requireZettle' => false,
                'name' => [
                    'en' => 'Karting trip',
                    'nl' => 'Kartuitje',
                ],
                'location' => [
                    'en' => 'Karting Track',
                    'nl' => 'Kartbaan',
                ],
                'costs' => [
                    'en' => '20 euro',
                    'nl' => '20 euro',
                ],
                'description' => [
                    'en' => 'An afternoon of karting. We drive there together, so at least one of the places goes to '
                        . 'somebody who can drive.',
                    'nl' => 'Een middag karten. We rijden er samen heen, dus minstens een van de plaatsen gaat naar '
                        . 'iemand die kan rijden.',
                ],
                'signupLists' => [
                    [
                        'name' => [
                            'en' => 'Attendance',
                            'nl' => 'Aanwezigheid',
                        ],
                        'openDate' => '-2 weeks 12:00',
                        'closeDate' => '-2 hours',
                        'onlyGEWIS' => true,
                        'displaySubscribedNumber' => true,
                        'limitedCapacity' => true,
                        'capacity' => 2,
                        'allocationMethod' => AllocationMethod::ConditionalDraw,
                        'drawCutoffRule' => DrawCutoffRule::OnClose,
                        'roles' => [
                            [
                                'name' => 'Driver',
                                'minimum' => 1,
                            ],
                        ],
                        'subscribers' => [
                            8005,
                            8006,
                            8007,
                            8008,
                        ],
                    ],
                ],
            ],
            ...$this->allocationMatrix(),
        ];

        foreach ($activities as $data) {
            $creator = $this->getReference(
                'member-' . $data['creator'],
                Member::class,
            );

            $activity = new Activity();
            $activity->setCreator($creator);

            // A seeded activity is a single-revision chain: revision 1 carries the content and its lifecycle state.
            $revision = $this->buildRevision(
                $data,
                $data['status'],
                $creator,
                1,
                null,
            );
            $revision->setRequireGEFLITST($data['requireGEFLITST']);
            $revision->setRequireZettle($data['requireZettle']);

            foreach ($data['labels'] ?? [] as $labelReference) {
                $revision->addLabel($this->getReference($labelReference, ActivityLabel::class));
            }

            // The organ behind the activity, which is what the places held for the organising body are read against.
            if (isset($data['organ'])) {
                $revision->setOrgan(
                    $this->getReference(
                        $data['organ'],
                        Organ::class,
                    ),
                );
            }

            // The organising company (a reviewable, display-only field) surfaces on that company's career detail page.
            if (isset($data['company'])) {
                $revision->setCompany(
                    $this->getReference(
                        'career-company-' . $data['company'],
                        Company::class,
                    ),
                );
            }

            $activity->addRevision($revision);
            $activity->setCurrentRevision($revision);

            if (RevisionStatus::Approved === $data['status']) {
                $activity->setLiveRevision($revision);
            }

            // Board lifecycle actions on an approved activity: cancel (stays public with a notice) or unpublish
            // (removed from public view). Both freeze sign-up interaction; the creator stands in as the board member.
            if ($data['cancelled'] ?? false) {
                $activity->cancel($creator);
            }

            if ($data['unpublished'] ?? false) {
                $activity->unpublish($creator);
            }

            $manager->persist($activity);
            $manager->persist($revision);

            foreach ($data['signupLists'] ?? [] as $signupListData) {
                $signupList = $this->createSignupList($signupListData);
                $revision->addSignupList($signupList);
                $manager->persist($signupList);

                // Sign-up fields (and their options for Choice fields), keyed by English name so a subscriber's
                // answers can reference them. Fields/options cascade-persist through the list.
                $fields = [];
                foreach ($signupListData['fields'] ?? [] as $fieldIndex => $fieldData) {
                    $field = new SignupField();
                    $field->setName(new ActivityLocalisedText($fieldData['name']['en'], $fieldData['name']['nl']));
                    $field->setType($fieldData['type']);
                    $field->setPosition($fieldIndex);
                    $signupList->addField($field);

                    $options = [];
                    foreach ($fieldData['options'] ?? [] as $optionIndex => $optionData) {
                        $option = new SignupOption();
                        $option->setValue(new ActivityLocalisedText($optionData['en'], $optionData['nl']));
                        $option->setPosition($optionIndex);
                        $option->setIsDefault($optionData['default'] ?? false);
                        $field->addOption($option);
                        $options[$optionData['en']] = $option;
                    }

                    $fields[$fieldData['name']['en']] = [
                        'field' => $field,
                        'options' => $options,
                    ];
                }

                $signups = [];
                foreach ($signupListData['subscribers'] ?? [] as $index => $subscriber) {
                    // A subscriber is either a bare lidnr or a richer array with presence and field answers.
                    $entry = is_array($subscriber)
                        ? $subscriber
                        : ['member' => $subscriber];

                    $signup = new UserSignup();
                    $signup->setSignupList($signupList);
                    $signup->setUser($this->getReference('member-' . $entry['member'], Member::class));
                    // A sign-up to a limited list starts on the waiting list (not drawn) until the organiser admits it;
                    // a sign-up to an unlimited list is admitted automatically. The future public subscribe flow MUST
                    // apply this same rule (drawn = !limitedCapacity) when it creates sign-ups.
                    $signup->setDrawn($entry['drawn'] ?? !$signupList->getLimitedCapacity());
                    $signup->setPresent($entry['present'] ?? false);
                    // A role is handed out by the organiser once sign-up has closed, so the draw has something to
                    // make up the shortfall from.
                    if (isset($entry['role'])) {
                        foreach ($signupList->getRoles() as $role) {
                            if ($role->getName() !== $entry['role']) {
                                continue;
                            }

                            $signup->setRole($role);
                        }
                    }

                    $signups[] = $signup;
                    $manager->persist($signup);
                    $this->signedUp(
                        $signup,
                        $index,
                    );

                    $this->addFieldAnswers(
                        $signup,
                        $fields,
                        $entry['answers'] ?? [],
                        $manager,
                    );
                }

                foreach ($signupListData['externals'] ?? [] as $index => $external) {
                    $signup = new ExternalSignup();
                    $signup->setSignupList($signupList);
                    $signup->setFullName($external['fullName']);
                    $signup->setEmail($external['email']);
                    // No token rows are seeded, so seeded externals are confirmed subscribers, mirroring the
                    // organiser-add path; without the stamp they would count as unverified everywhere.
                    $signup->setVerifiedAt($this->signedUp(
                        $signup,
                        count($signupListData['subscribers'] ?? []) + $index,
                    ));
                    $signup->setDrawn($external['drawn']);
                    $signup->setPresent($external['present']);
                    $signups[] = $signup;
                    $manager->persist($signup);

                    $this->addFieldAnswers(
                        $signup,
                        $fields,
                        $external['answers'],
                        $manager,
                    );
                }

                // A list that says it has been drawn carries the outcome the draw itself would produce: the pool in
                // the order the modifiers put it in, the first capacity of it admitted, and everybody holding the
                // place they were given. Seeding that by hand would be seeding a claim about the algorithm.
                if ($signupListData['draw'] ?? false) {
                    $capacity = $signupList->getCapacity() ?? 0;
                    $position = 0;

                    foreach (
                        new AdmissionOrder()->arrange(
                            $signupList,
                            $signups,
                        ) as $signup
                    ) {
                        $signup->setDrawn($position < $capacity);
                        $signup->setDrawPosition(++$position);
                    }

                    continue;
                }

                // A list whose sign-ups say by hand who was admitted keeps that, with the admitted ones in front.
                if (!$signupList->isDrawLocked()) {
                    continue;
                }

                $position = 0;
                foreach ([true, false] as $admitted) {
                    foreach ($signups as $signup) {
                        if ($signup->isDrawn() !== $admitted) {
                            continue;
                        }

                        $signup->setDrawPosition(++$position);
                    }
                }
            }
        }

        $this->loadWorkflowExamples($manager);

        $manager->flush();

        // The entity stamps its creation on persist, so the moments the sign-ups were made are written afterwards.
        assert($manager instanceof EntityManagerInterface);
        foreach ($this->signedUpAt as [$signup, $at]) {
            $manager->getConnection()->update(
                'Signup',
                [
                    'createdAt' => $at->format('Y-m-d H:i:s'),
                    'updatedAt' => $at->format('Y-m-d H:i:s'),
                ],
                ['id' => $signup->getId()],
            );
        }
    }

    /**
     * When a sign-up was made: a few minutes into its list's window, one after the other, so a list that has closed
     * or been drawn holds sign-ups from before that moment rather than from the moment the seed ran. A list that
     * has not opened yet keeps the seeding moment, which is all a sign-up on it could have.
     */
    private function signedUp(
        Signup $signup,
        int $index,
    ): DateTime {
        $openDate = $signup->getSignupList()->getOpenDate();
        $now = new DateTime();

        if (
            null === $openDate
            || $openDate > $now
        ) {
            return $now;
        }

        $at = (clone $openDate)->modify(sprintf(
            '+%d minutes',
            $index + 1,
        ));
        if ($at > $now) {
            return $now;
        }

        $this->signedUpAt[] = [
            $signup,
            $at,
        ];

        return $at;
    }

    /**
     * The allocation matrix: every way of deciding who gets a place, in every state a list can be in, so each of the
     * board's screens can be looked at before, at and after the moment the places are handed out. Coded the way the
     * members requiring attention are: the letter says what the list does, the number how far along it is.
     *
     * @return list<ActivitySeedType>
     */
    private function allocationMatrix(): array
    {
        // Enough of a cast that a ranking shows: ordinary members of four generations, an external member, an
        // honorary member, a graduate, a master student and somebody doing a doctorate.
        $cast = [
            8005,
            8010,
            8006,
            8100,
            8115,
            21,
            8155,
            22,
            8007,
        ];
        $guests = [
            [
                'fullName' => 'Wietske Groen',
                'email' => 'wietske@example.org',
                'drawn' => false,
                'present' => false,
                'answers' => [],
            ],
            [
                'fullName' => 'Bram de Ruiter',
                'email' => 'bram@example.org',
                'drawn' => false,
                'present' => false,
                'answers' => [],
            ],
        ];

        $membership = [
            'membershipTierOrder' => [
                [
                    MembershipTier::Ordinary,
                    MembershipTier::External,
                    MembershipTier::Honorary,
                ],
                [MembershipTier::Graduate],
                [MembershipTier::NonMember],
            ],
        ];
        $roles = [
            'roles' => [
                [
                    'name' => 'Driver',
                    'minimum' => 2,
                ],
            ],
        ];

        $configs = [
            'A' => [
                'does' => [
                    'en' => 'Members first',
                    'nl' => 'Leden eerst',
                ],
                'says' => [
                    'en' => 'Anybody may sign up, and the places go to the members before the outside world.',
                    'nl' => 'Iedereen mag zich inschrijven en de plaatsen gaan naar de leden voor de buitenwereld.',
                ],
                'method' => AllocationMethod::FirstComeFirstServed,
                'settings' => $membership + ['membershipPriorityMode' => MembershipPriorityMode::Ordering],
            ],
            'B' => [
                'does' => [
                    'en' => 'Places held back',
                    'nl' => 'Gereserveerde plaatsen',
                ],
                'says' => [
                    'en' => 'Places are held for the organising body and for each rank of the membership order; what '
                        . 'is left over is open to everybody.',
                    'nl' => 'Er zijn plaatsen gereserveerd voor het organiserende orgaan en voor elke groep van de '
                        . 'ledenvolgorde; wat overblijft is voor iedereen.',
                ],
                'organ' => 'organ-keur',
                'capacity' => 8,
                // First-come-first-served, so the board runs the draw itself: a conditional draw that is due is
                // drawn by the scheduler within the minute, and there would be no closed-and-waiting state to look
                // at. The modifiers act on either method the same way.
                'method' => AllocationMethod::FirstComeFirstServed,
                'settings' => $membership + [
                    'membershipPriorityMode' => MembershipPriorityMode::ReservedPlaces,
                    'membershipPlaces' => [
                        'ordinary+external+honorary' => 3,
                        'graduate' => 1,
                        'non-member' => 1,
                    ],
                    'organisingCommitteePlaces' => 2,
                ],
                'subscribers' => [
                    8025,
                    8026,
                    ...$cast,
                ],
            ],
            'C' => [
                'does' => [
                    'en' => 'First years first',
                    'nl' => 'Eerstejaars eerst',
                ],
                'says' => [
                    'en' => 'The newest members are meant to have the places, so the cohort decides.',
                    'nl' => 'De nieuwste leden horen de plaatsen te krijgen, dus het cohort beslist.',
                ],
                'onlyGEWIS' => true,
                'method' => AllocationMethod::FirstComeFirstServed,
                'settings' => [
                    'cohortTierOrder' => [
                        [CohortTier::FirstYear],
                        [CohortTier::SecondYear],
                        [CohortTier::Senior],
                        [CohortTier::Unknown],
                    ],
                ],
            ],
            'D' => [
                'does' => [
                    'en' => 'Master students first',
                    'nl' => 'Masterstudenten eerst',
                ],
                'says' => [
                    'en' => 'The material is aimed at the master, so the phase of the study decides. The draw runs a '
                        . 'day after the list opens rather than at its close.',
                    'nl' => 'De stof is op de master gericht, dus de fase van de studie beslist. Er wordt een dag na '
                        . 'het openen geloot in plaats van bij het sluiten.',
                ],
                'onlyGEWIS' => true,
                'method' => AllocationMethod::ConditionalDraw,
                'settings' => [
                    'programTypeOrder' => [
                        [ProgramType::Master],
                        [ProgramType::Doctorate],
                        [ProgramType::Bachelor],
                        [ProgramType::Other],
                    ],
                    'drawCutoffRule' => DrawCutoffRule::AfterDurationOpen,
                    'drawAfterDurationHours' => 24,
                ],
                'states' => [
                    1,
                    4,
                    3,
                ],
                'perState' => [
                    3 => ['drawnAt' => '-20 days 12:00'],
                    4 => ['drawnAt' => '-6 days 12:00'],
                ],
            ],
            'E' => [
                'does' => [
                    'en' => 'A guaranteed role',
                    'nl' => 'Een gegarandeerde rol',
                ],
                'says' => [
                    'en' => 'The activity cannot go ahead without two drivers, so two of the places are guaranteed '
                        . 'to them. The roles are handed out once sign-up has closed, and the draw waits for that.',
                    'nl' => 'De activiteit kan niet doorgaan zonder twee chauffeurs, dus twee plaatsen zijn voor hen '
                        . 'gegarandeerd. De rollen worden na het sluiten uitgedeeld en de loting wacht daarop.',
                ],
                'onlyGEWIS' => true,
                'method' => AllocationMethod::ConditionalDraw,
                'settings' => $roles + ['drawCutoffRule' => DrawCutoffRule::OnClose],
                'roleHolders' => [
                    8155,
                    8007,
                ],
            ],
            'F' => [
                'does' => [
                    'en' => 'Everything at once',
                    'nl' => 'Alles tegelijk',
                ],
                'says' => [
                    'en' => 'An order, places held for the organising body, and a role the activity cannot go ahead '
                        . 'without, all on the one list. The draw runs at the moment the organiser named.',
                    'nl' => 'Een volgorde, plaatsen voor het organiserende orgaan en een rol waar de activiteit niet '
                        . 'zonder kan, allemaal op dezelfde lijst. Er wordt geloot op het moment dat de organisator '
                        . 'heeft genoemd.',
                ],
                'organ' => 'organ-keur',
                'capacity' => 6,
                'method' => AllocationMethod::ConditionalDraw,
                'settings' => $membership + $roles + [
                    'membershipPriorityMode' => MembershipPriorityMode::Ordering,
                    'organisingCommitteePlaces' => 1,
                    'cohortTierOrder' => [
                        [CohortTier::FirstYear],
                        [CohortTier::SecondYear],
                        [CohortTier::Senior],
                        [CohortTier::Unknown],
                    ],
                    'drawCutoffRule' => DrawCutoffRule::IfFullBefore,
                ],
                'subscribers' => [
                    8025,
                    ...$cast,
                ],
                'roleHolders' => [
                    8155,
                    8100,
                ],
                // A list that guarantees a role is drawn by hand once it has closed, so it never stands drawn
                // while sign-up is still running, whatever its own moment says.
                'perState' => [
                    1 => ['drawCutoffAt' => '+3 days 12:00'],
                    2 => ['drawCutoffAt' => '-1 day 12:00'],
                    3 => ['drawCutoffAt' => '-4 days 12:00'],
                ],
            ],
            'G' => [
                'does' => [
                    'en' => 'Nothing at all',
                    'nl' => 'Niets bijzonders',
                ],
                'says' => [
                    'en' => 'A limited list that ranks nobody and holds nothing back, which is what the draw was '
                        . 'before any of this.',
                    'nl' => 'Een beperkte lijst die niemand rangschikt en niets reserveert, zoals de loting was voor '
                        . 'dit alles.',
                ],
                'method' => AllocationMethod::FirstComeFirstServed,
                'settings' => [],
            ],
            'H' => [
                'does' => [
                    'en' => 'The committee decides',
                    'nl' => 'De commissie beslist',
                ],
                'says' => [
                    'en' => 'The committee picks who comes along, so nothing here is ranked or drawn at all.',
                    'nl' => 'De commissie kiest wie er meegaat, dus hier wordt niets gerangschikt of geloot.',
                ],
                'onlyGEWIS' => true,
                'method' => AllocationMethod::Custom,
                'settings' => [
                    'customMethodDescription' => 'The committee picks a group that can share four cars.',
                ],
                'states' => [
                    1,
                    2,
                ],
            ],
            'I' => [
                'does' => [
                    'en' => 'Somebody else decides',
                    'nl' => 'Iemand anders beslist',
                ],
                'says' => [
                    'en' => 'The places are handed out elsewhere, so this list only says who put their name down.',
                    'nl' => 'De plaatsen worden elders uitgedeeld, dus deze lijst zegt alleen wie zich heeft '
                        . 'opgegeven.',
                ],
                'method' => AllocationMethod::ExternalParty,
                'settings' => ['externalPolicyUrl' => 'https://example.org/tickets'],
                'states' => [
                    1,
                    2,
                ],
            ],
        ];

        $states = [
            1 => [
                'is' => [
                    'en' => 'still open',
                    'nl' => 'nog open',
                ],
                'says' => [
                    'en' => 'Sign-up is still running and nothing has been decided.',
                    'nl' => 'De inschrijving loopt nog en er is nog niets beslist.',
                ],
                'openDate' => '-4 hours',
                'closeDate' => '+6 days 18:00',
            ],
            2 => [
                'is' => [
                    'en' => 'closed, nobody admitted yet',
                    'nl' => 'gesloten, nog niemand toegelaten',
                ],
                'says' => [
                    'en' => 'Sign-up has closed and the places have not been handed out yet.',
                    'nl' => 'De inschrijving is gesloten en de plaatsen zijn nog niet uitgedeeld.',
                ],
                'openDate' => '-3 weeks 12:00',
                'closeDate' => '-2 hours',
            ],
            3 => [
                'is' => [
                    'en' => 'closed and drawn',
                    'nl' => 'gesloten en geloot',
                ],
                'says' => [
                    'en' => 'The places have been handed out, so the waiting list stands in the order it was ranked '
                        . 'in.',
                    'nl' => 'De plaatsen zijn uitgedeeld, dus de wachtlijst staat in de volgorde waarin er '
                        . 'gerangschikt is.',
                ],
                'openDate' => '-3 weeks 12:00',
                'closeDate' => '-2 days 18:00',
                'draw' => true,
                'drawnAt' => '-2 days 18:00',
                'drawnBy' => 8025,
            ],
            4 => [
                'is' => [
                    'en' => 'drawn while still open',
                    'nl' => 'geloot terwijl nog open',
                ],
                'says' => [
                    'en' => 'The draw ran at its own moment, well before sign-up closes, so anybody signing up now '
                        . 'joins the waiting list behind the people who were in at that moment.',
                    'nl' => 'De loting is op het eigen moment gedaan, ruim voordat de inschrijving sluit, dus wie '
                        . 'zich nu opgeeft komt achter de mensen die er toen bij waren op de wachtlijst.',
                ],
                'openDate' => '-1 week 12:00',
                'closeDate' => '+2 days 18:00',
                'draw' => true,
                'drawnAt' => '-5 days 12:00',
            ],
        ];

        $activities = [];
        $day = 2;

        foreach ($configs as $letter => $config) {
            foreach ($config['states'] ?? [1, 2, 3] as $number) {
                $state = $states[$number];
                $onlyGEWIS = $config['onlyGEWIS'] ?? false;
                $subscribers = $config['subscribers'] ?? $cast;
                // The roles are handed out once sign-up has closed, which is before the draw rather than with it.
                $holders = 1 === $number
                    ? []
                    : $config['roleHolders'] ?? [];

                $activities[] = [
                    'creator' => 8025,
                    ...isset($config['organ']) ? ['organ' => $config['organ']] : [],
                    'status' => RevisionStatus::Approved,
                    'beginTime' => sprintf(
                        '+%d days 18:00',
                        $day,
                    ),
                    'endTime' => sprintf(
                        '+%d days 22:00',
                        $day++,
                    ),
                    'category' => ActivityCategories::Recreational,
                    'requireGEFLITST' => false,
                    'requireZettle' => false,
                    'name' => [
                        'en' => sprintf(
                            'ÅLLOC-%s%d %s, %s',
                            $letter,
                            $number,
                            $config['does']['en'],
                            $state['is']['en'],
                        ),
                        'nl' => sprintf(
                            'ÅLLOC-%s%d %s, %s',
                            $letter,
                            $number,
                            $config['does']['nl'],
                            $state['is']['nl'],
                        ),
                    ],
                    'location' => [
                        'en' => 'Nexus',
                        'nl' => 'Nexus',
                    ],
                    'costs' => [
                        'en' => 'Free',
                        'nl' => 'Gratis',
                    ],
                    'description' => [
                        'en' => $config['says']['en'] . ' ' . $state['says']['en'],
                        'nl' => $config['says']['nl'] . ' ' . $state['says']['nl'],
                    ],
                    'signupLists' => [
                        [
                            'name' => [
                                'en' => 'Attendance',
                                'nl' => 'Aanwezigheid',
                            ],
                            'openDate' => $state['openDate'],
                            'closeDate' => $state['closeDate'],
                            'onlyGEWIS' => $onlyGEWIS,
                            'displaySubscribedNumber' => true,
                            'limitedCapacity' => true,
                            'capacity' => $config['capacity'] ?? 4,
                            'allocationMethod' => $config['method'],
                            ...$config['settings'],
                            ...$state['draw'] ?? false ? [
                                'draw' => true,
                                'drawnAt' => $state['drawnAt'],
                                ...isset($state['drawnBy']) ? ['drawnBy' => $state['drawnBy']] : [],
                            ] : [],
                            ...$config['perState'][$number] ?? [],
                            'subscribers' => array_map(
                                static fn (int $lidnr): array => [
                                    'member' => $lidnr,
                                    ...in_array(
                                        $lidnr,
                                        $holders,
                                        true,
                                    ) ? ['role' => 'Driver'] : [],
                                ],
                                $subscribers,
                            ),
                            'externals' => $onlyGEWIS ? [] : $guests,
                        ],
                    ],
                ];
            }
        }

        return $activities;
    }

    /**
     * Seeds activities that exercise the revision workflow: one awaiting review, one bounced back with a
     * changes-requested chain and a discussion thread, and one rejected with reviewer feedback.
     */
    private function loadWorkflowExamples(ObjectManager $manager): void
    {
        $boardA = $this->getReference(
            'member-8025',
            Member::class,
        );
        $boardB = $this->getReference(
            'member-8026',
            Member::class,
        );

        // The workflow examples are organised by an organ, so organ-scoped visibility/edit rights have something to
        // resolve against. GETÉST and KEUR have disjoint members, so the two can be told apart.
        $getest = $this->getReference(
            'organ-getest',
            Organ::class,
        );
        $keur = $this->getReference(
            'organ-keur',
            Organ::class,
        );

        // In review: sits in the board's review queue (no live revision, so not publicly visible).
        $hackathonCreator = $this->getReference(
            'member-8013',
            Member::class,
        );
        $hackathon = new Activity();
        $hackathon->setCreator($hackathonCreator);
        $hackathonRevision = $this->buildRevision(
            [
                'name' => [
                    'en' => 'Hackathon',
                    'nl' => 'Hackathon',
                ],
                'location' => [
                    'en' => 'MetaForum',
                    'nl' => 'MetaForum',
                ],
                'costs' => [
                    'en' => 'Free',
                    'nl' => 'Gratis',
                ],
                'description' => [
                    'en' => 'A 24-hour hackathon.',
                    'nl' => 'Een 24-uurs hackathon.',
                ],
                'beginTime' => '+8 weeks 18:00',
                'endTime' => '+8 weeks +1 day 18:00',
                'category' => ActivityCategories::Competition,
            ],
            RevisionStatus::InReview,
            $hackathonCreator,
            1,
            null,
        );
        $hackathonRevision->setOrgan($getest);
        $hackathon->addRevision($hackathonRevision);
        $hackathon->setCurrentRevision($hackathonRevision);
        $manager->persist($hackathon);
        $manager->persist($hackathonRevision);

        // Changes requested: revision 1 is an immutable record with a discussion thread; revision 2 (draft) continues.
        $beerCreator = $this->getReference(
            'member-8010',
            Member::class,
        );
        $beer = new Activity();
        $beer->setCreator($beerCreator);
        $beerRevision1 = $this->buildRevision(
            [
                'name' => [
                    'en' => 'Beer Tasting',
                    'nl' => 'Bierproeverij',
                ],
                'location' => [
                    'en' => 'Common Room',
                    'nl' => 'Huiskamer',
                ],
                'costs' => [
                    'en' => '',
                    'nl' => '',
                ],
                'description' => [
                    'en' => 'Tasting of local beers.',
                    'nl' => 'Proeverij van lokale bieren.',
                ],
                'beginTime' => '+4 weeks 20:00',
                'endTime' => '+4 weeks 23:00',
                'category' => ActivityCategories::SocialDrink,
            ],
            RevisionStatus::ChangesRequested,
            $beerCreator,
            1,
            null,
        );
        $beerRevision1->setReviewer($boardA);
        $beerRevision1->setReviewedAt(new DateTime('-2 days'));
        $beerRevision1->setOrgan($getest);
        $beer->addRevision($beerRevision1);
        $manager->persist($beer);
        $manager->persist($beerRevision1);
        $this->comment(
            $beerRevision1,
            $boardA,
            'Please add the price and confirm the location is booked.',
            $manager,
        );
        $this->comment(
            $beerRevision1,
            $beerCreator,
            'Updated the details — the room is booked and it is free for members.',
            $manager,
        );
        $beerRevision2 = $this->buildRevision(
            [
                'name' => [
                    'en' => 'Beer Tasting',
                    'nl' => 'Bierproeverij',
                ],
                'location' => [
                    'en' => 'Common Room (booked)',
                    'nl' => 'Huiskamer (geboekt)',
                ],
                'costs' => [
                    'en' => 'Free for members',
                    'nl' => 'Gratis voor leden',
                ],
                'description' => [
                    'en' => 'Tasting of local beers.',
                    'nl' => 'Proeverij van lokale bieren.',
                ],
                'beginTime' => '+4 weeks 20:00',
                'endTime' => '+4 weeks 23:00',
                'category' => ActivityCategories::SocialDrink,
            ],
            RevisionStatus::Draft,
            $beerCreator,
            2,
            $beerRevision1,
        );
        $beerRevision2->setOrgan($getest);
        $beer->addRevision($beerRevision2);
        $beer->setCurrentRevision($beerRevision2);
        $manager->persist($beerRevision2);

        // Rejected, with reviewer feedback.
        $casinoCreator = $this->getReference(
            'member-8012',
            Member::class,
        );
        $casino = new Activity();
        $casino->setCreator($casinoCreator);
        $casinoRevision = $this->buildRevision(
            [
                'name' => [
                    'en' => 'Casino Night',
                    'nl' => 'Casinoavond',
                ],
                'location' => [
                    'en' => 'Association Room',
                    'nl' => 'Verenigingskamer',
                ],
                'costs' => [
                    'en' => '10 euro',
                    'nl' => '10 euro',
                ],
                'description' => [
                    'en' => 'An evening of card games.',
                    'nl' => 'Een avond vol kaartspellen.',
                ],
                'beginTime' => '+5 weeks 20:00',
                'endTime' => '+5 weeks 23:30',
                'category' => ActivityCategories::Recreational,
            ],
            RevisionStatus::Rejected,
            $casinoCreator,
            1,
            null,
        );
        $casinoRevision->setReviewer($boardB);
        $casinoRevision->setReviewedAt(new DateTime('-5 days'));
        // KEUR (disjoint from GETÉST) so organ scoping can be told apart between the two organs.
        $casinoRevision->setOrgan($keur);
        $casino->addRevision($casinoRevision);
        $casino->setCurrentRevision($casinoRevision);
        $manager->persist($casino);
        $manager->persist($casinoRevision);
        $this->comment(
            $casinoRevision,
            $boardB,
            'Gambling activities are not permitted; please propose an alternative.',
            $manager,
        );
    }

    /**
     * @phpstan-param array{
     *     name: array{en: string, nl: string},
     *     location: array{en: string, nl: string},
     *     costs: array{en: string, nl: string},
     *     description: array{en: string, nl: string},
     *     beginTime: string,
     *     endTime: string,
     *     category: ActivityCategories,
     *     ...<string, mixed>,
     * } $content the activity content; the main loop passes a wider row (with creator, labels, sign-up lists, ...)
     */
    private function buildRevision(
        array $content,
        RevisionStatus $status,
        Member $author,
        int $revisionNumber,
        ?ActivityRevision $previous,
    ): ActivityRevision {
        $revision = new ActivityRevision();
        $revision->setAuthor($author);
        $revision->setStatus($status);

        // What the board still has to look at is dated, since a queue is ordered and coloured by how long something
        // has been waiting on it.
        if (
            RevisionStatus::Submitted === $status
            || RevisionStatus::InReview === $status
        ) {
            $revision->setSubmittedAt(new DateTime('-2 days'));
        }

        $revision->setRevisionNumber($revisionNumber);
        $revision->setPreviousRevision($previous);
        $revision->setName(new ActivityLocalisedText($content['name']['en'], $content['name']['nl']));
        $revision->setLocation(new ActivityLocalisedText($content['location']['en'], $content['location']['nl']));
        $revision->setCosts(new ActivityLocalisedText($content['costs']['en'], $content['costs']['nl']));
        $revision->setDescription(
            new ActivityLocalisedText(
                $content['description']['en'],
                $content['description']['nl'],
            ),
        );
        $revision->setBeginTime(new DateTime($content['beginTime']));
        $revision->setEndTime(new DateTime($content['endTime']));
        $revision->setCategory($content['category']);

        return $revision;
    }

    private function comment(
        ActivityRevision $revision,
        Member $author,
        string $body,
        ObjectManager $manager,
    ): void {
        $comment = new ActivityRevisionComment();
        $comment->setRevision($revision);
        // The comment author is the member's user account (a CompanyUser would author careers comments); the board
        // members who comment all have a seeded user.
        $comment->setAuthor($this->getReference('user-' . $author->getLidnr(), User::class));
        $comment->setBody($body);
        $manager->persist($comment);
    }

    /**
     * @param array<string, mixed> $data
     * @phpstan-param SignupListSeedType $data
     */
    private function createSignupList(array $data): SignupList
    {
        $signupList = new SignupList();
        $signupList->setName(new ActivityLocalisedText($data['name']['en'], $data['name']['nl']));
        $signupList->setOpenDate(new DateTime($data['openDate']));
        $signupList->setCloseDate(new DateTime($data['closeDate']));
        $signupList->setOnlyGEWIS($data['onlyGEWIS']);
        $signupList->setDisplaySubscribedNumber($data['displaySubscribedNumber']);
        $signupList->setLimitedCapacity($data['limitedCapacity']);
        $signupList->setCapacity($data['capacity'] ?? null);
        $signupList->setAllocationMethod($data['allocationMethod'] ?? AllocationMethod::FirstComeFirstServed);
        $signupList->setDrawCutoffRule($data['drawCutoffRule'] ?? null);
        $signupList->setDrawCutoffAt(isset($data['drawCutoffAt']) ? new DateTime($data['drawCutoffAt']) : null);
        $signupList->setDrawAfterDurationHours($data['drawAfterDurationHours'] ?? null);
        $signupList->setExternalPolicyUrl($data['externalPolicyUrl'] ?? null);
        $signupList->setCustomMethodDescription($data['customMethodDescription'] ?? null);
        $signupList->setPromoted($data['promoted'] ?? false);
        $signupList->setPresenceTaken($data['presenceTaken'] ?? false);
        $signupList->setMembershipTierOrder($data['membershipTierOrder'] ?? null);
        $signupList->setMembershipPriorityMode($data['membershipPriorityMode'] ?? null);
        $signupList->setHeldMembershipPlaces($data['membershipPlaces'] ?? null);
        $signupList->setCohortTierOrder($data['cohortTierOrder'] ?? null);
        $signupList->setProgramTypeOrder($data['programTypeOrder'] ?? null);
        $signupList->setOrganisingCommitteePlaces($data['organisingCommitteePlaces'] ?? null);

        $position = 0;
        foreach ($data['roles'] ?? [] as $role) {
            $signupRole = new SignupRole();
            $signupRole->setName($role['name']);
            $signupRole->setMinimum($role['minimum']);
            $signupRole->setPosition($position++);
            $signupList->addRole($signupRole);
        }

        // A list that has already been drawn carries its lock and its audit: a board member by lidnr, or nobody at
        // all, which is what an automated draw leaves behind.
        if (isset($data['drawnAt'])) {
            $signupList->setDrawnAt(new DateTime($data['drawnAt']));

            if (isset($data['drawnBy'])) {
                $signupList->setDrawnBy($this->getReference('member-' . $data['drawnBy'], Member::class));
            }
        }

        return $signupList;
    }

    /**
     * Attach a sign-up's answers to the given fields. Choice answers reference an option by its English value; every
     * other type stores the raw string.
     *
     * @param array<string, array{field: SignupField, options: array<string, SignupOption>}> $fields  by field name
     * @param array<string, string>                                                          $answers by field name
     */
    private function addFieldAnswers(
        Signup $signup,
        array $fields,
        array $answers,
        ObjectManager $manager,
    ): void {
        foreach ($answers as $fieldName => $answer) {
            if (!isset($fields[$fieldName])) {
                continue;
            }

            $field = $fields[$fieldName]['field'];
            $fieldValue = new SignupFieldValue();
            $fieldValue->setSignup($signup);
            $fieldValue->setField($field);

            if (SignupFieldTypes::Choice === $field->getType()) {
                $fieldValue->setOption($fields[$fieldName]['options'][$answer] ?? null);
            } else {
                $fieldValue->setValue($answer);
            }

            $manager->persist($fieldValue);
        }
    }

    /**
     * @return array<array-key, class-string<Fixture>>
     */
    #[Override]
    public function getDependencies(): array
    {
        return [
            ProjectionReferenceFixture::class,
            ActivityLabelFixture::class,
            UserFixture::class,
            // The workflow examples are assigned an organising organ, so the organs must be seeded first.
            ProjectionReferenceFixture::class,
            // Some seeded activities name an organising company, so the companies must be seeded first.
            CompanyFixture::class,
        ];
    }

    /**
     * @return string[]
     */
    #[Override]
    public static function getGroups(): array
    {
        return ['web'];
    }
}
