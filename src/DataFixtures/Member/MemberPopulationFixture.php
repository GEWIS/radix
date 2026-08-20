<?php

declare(strict_types=1);

namespace App\DataFixtures\Member;

use App\Entity\Database\Address;
use App\Entity\Database\Enums\AddressTypes;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Enums\PostalRegions;
use App\Entity\Database\Enums\Studies;
use App\Entity\Database\Member;
use App\Entity\Database\Membership;
use DateInterval;
use DateTime;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Override;

use function array_map;
use function chr;
use function count;
use function explode;
use function hash;
use function implode;
use function in_array;
use function mb_substr;
use function range;
use function sprintf;

/**
 * The body of members the association is made of, so that every surface that lists people has a realistic number of
 * them rather than the handful its neighbours are built out of.
 *
 * These live in the ledger, like every other member: a member is a ledger record, and the projection the website reads
 * is replayed from it. What the projection ends up saying about somebody -- their type, when their membership expires,
 * their addresses -- is derived from the memberships and addresses written here rather than set on the far side, which
 * is why none of it is set twice.
 *
 * The numbering is fixed rather than left to the sequence. The fixtures that hang off these members name them by
 * `lidnr`, and a seed whose members come back under different numbers every run cannot be referred to at all. Their
 * ranges say what each block is for; {@see \App\DataFixtures\Database\DecisionFixture} installs the ones that belong
 * in a body, and it names them by the same numbers.
 */
final class MemberPopulationFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    /**
     * Where each block of the population starts, and what it is for.
     *
     * Read as half-open ranges against the next entry: the admins are 8000 to 8002, the company admins 8003 to 8004,
     * and so on to the last block, which ends at {@see self::LAST}. The graduates start at 8155 rather than where the
     * honorary members leave off, because the fixtures hanging off them name those numbers; the gap between is
     * deliberate, and a member number is not required to have a neighbour.
     */
    public const int ADMIN = 8000;
    public const int COMPANY_ADMIN = 8003;
    public const int ORGAN_ORDINARY = 8005;
    public const int ORGAN_EXTERNAL = 8015;
    public const int ORGAN_DISCHARGED = 8020;
    public const int BOARD = 8025;
    public const int ORDINARY = 8030;
    public const int EXTERNAL = 8100;
    public const int HONORARY = 8115;
    public const int HONORARY_LAST = 8119;
    public const int GRADUATE = 8155;
    public const int HIDDEN = 8200;
    public const int DELETED = 8205;
    public const int LAST = 8209;

    /**
     * Given names to draw from, Dutch and not.
     *
     * The association is a Dutch one whose members are not all Dutch, so both belong in a seed; a list of each is
     * enough for that and is one fewer dependency than a generator. Which name a member gets is decided by their
     * number, so the same seed comes back the same way twice.
     */
    private const array FIRST_NAMES = [
        'Sanne',
        'Bram',
        'Fenna',
        'Joost',
        'Marieke',
        'Thijs',
        'Lotte',
        'Sander',
        'Anouk',
        'Ruben',
        'Femke',
        'Wouter',
        'Maaike',
        'Bas',
        'Roos',
        'Jeroen',
        'Eva',
        'Daan',
        'Julia',
        'Stijn',
        'Noor',
        'Tijmen',
        'Iris',
        'Koen',
        'Aditi',
        'Mateusz',
        'Yusuf',
        'Chiara',
        'Andrei',
        'Priya',
        'Tomás',
        'Ingrid',
        'Kwame',
        'Elena',
        'Rasmus',
        'Leila',
        'Hiroshi',
        'Sofia',
        'Omar',
        'Nadia',
    ];

    /**
     * Family names, with the Dutch ones keeping the particle that belongs to them.
     */
    private const array LAST_NAMES = [
        'Jansen',
        'de Vries',
        'van den Berg',
        'Bakker',
        'Visser',
        'Smit',
        'Meijer',
        'de Boer',
        'Mulder',
        'de Groot',
        'Bos',
        'Vos',
        'Peters',
        'Hendriks',
        'van Leeuwen',
        'Dekker',
        'Brouwer',
        'de Wit',
        'Dijkstra',
        'Smits',
        'van der Meer',
        'Kok',
        'Jacobs',
        'Vermeulen',
        'Kowalski',
        'Nowak',
        'Ferreira',
        'Rossi',
        'Nakamura',
        'Okafor',
        'Andersson',
        'Novák',
        'Haddad',
        'Kaur',
        'Lindqvist',
        'Marchetti',
    ];

    /**
     * Towns a member might live in, so an address reads as one.
     */
    private const array CITIES = [
        'Eindhoven',
        'Veldhoven',
        'Best',
        'Nuenen',
        'Geldrop',
        'Helmond',
        'Waalre',
        'Son en Breugel',
        'Tilburg',
        'Den Bosch',
    ];

    /**
     * Streets to put a number on.
     */
    private const array STREETS = [
        'Kerkstraat',
        'Dorpsstraat',
        'Molenweg',
        'Stationsstraat',
        'Nieuwstraat',
        'Beukenlaan',
        'Julianastraat',
        'Wilhelminalaan',
        'Parklaan',
        'Vestdijk',
    ];

    /**
     * The members whose birthday falls on the day the seed is loaded, so the front page's birthday panel has somebody
     * to show without a row being edited by hand.
     */
    private const array BORN_TODAY = [
        8006,
        8007,
    ];

    /**
     * Reachable only at a university address, so the notice telling somebody to give the association an address that
     * outlives their studies has a member to appear for.
     */
    private const int STUDENT_ADDRESS_ONLY = 8005;

    private DateTime $now;

    #[Override]
    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('The ledger fixtures need the ORM to assign member numbers.');
        }

        $this->now = new DateTime();

        // `lidnr` is generated from a sequence, which cannot produce the numbers these are referred to by. The
        // generator is dropped for the rest of the run so the numbers below are taken as given. The sequence itself is
        // left where it stands, well below this range, so anything creating a member afterwards still gets a free
        // number.
        $metadata = $manager->getClassMetadata(Member::class);
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
        $metadata->setIdGenerator(new AssignedGenerator());

        // Ordinary members who hold an account with rights on the website. Named apart from the rank and file so a
        // seeded login always lands on the same person.
        $this->block(
            $manager,
            self::ADMIN,
            self::COMPANY_ADMIN - 1,
            MembershipTypes::Ordinary,
            'ÅDMIN',
        );
        $this->block(
            $manager,
            self::COMPANY_ADMIN,
            self::ORGAN_ORDINARY - 1,
            MembershipTypes::Ordinary,
            'ÇOMPANY',
        );

        // The ones a decision puts in a body: two blocks that are still in one, and a third that was discharged and
        // therefore reads as a former member of it.
        $this->block(
            $manager,
            self::ORGAN_ORDINARY,
            self::ORGAN_EXTERNAL - 1,
            MembershipTypes::Ordinary,
            'BÖDY',
        );
        $this->block(
            $manager,
            self::ORGAN_EXTERNAL,
            self::ORGAN_DISCHARGED - 1,
            MembershipTypes::External,
            'BÖDY-EXTERNAL',
        );
        $this->block(
            $manager,
            self::ORGAN_DISCHARGED,
            self::BOARD - 1,
            MembershipTypes::Ordinary,
            'DÏSCHARGED',
        );
        $this->block(
            $manager,
            self::BOARD,
            self::ORDINARY - 1,
            MembershipTypes::Ordinary,
            'BÔARD',
        );

        // The rest of the association. Graduates are the one block that is allowed to have lapsed, because a graduate
        // who stopped renewing is the ordinary way for a membership to end.
        $this->block(
            $manager,
            self::ORDINARY,
            self::EXTERNAL - 1,
            MembershipTypes::Ordinary,
        );
        $this->block(
            $manager,
            self::EXTERNAL,
            self::HONORARY - 1,
            MembershipTypes::External,
            'EXTÉRNAL',
        );
        $this->block(
            $manager,
            self::HONORARY,
            self::HONORARY_LAST,
            MembershipTypes::Honorary,
            'HÖNORARY',
        );
        $this->block(
            $manager,
            self::GRADUATE,
            self::HIDDEN - 1,
            MembershipTypes::Graduate,
            'GRÄDUATE',
            lapsedShare: true,
        );

        // Kept off every list the website shows, at their own request. Nothing else about them differs, which is the
        // point: a page that forgets to filter them shows somebody who asked not to be shown.
        $this->block(
            $manager,
            self::HIDDEN,
            self::DELETED - 1,
            MembershipTypes::Ordinary,
            'HÌDDEN',
            hidden: true,
        );

        // Removed, but still named by the decisions that were taken about them. A decision is the association's own
        // record and does not go away because somebody left, so what is left of them is a name and nothing else.
        $this->block(
            $manager,
            self::DELETED,
            self::LAST,
            MembershipTypes::Ordinary,
            'DÊLETED',
            deleted: true,
        );

        $manager->flush();
    }

    /**
     * One block of the population, numbered from `$from` to `$to` inclusive.
     *
     * `$firstName` fixes the given name where the block is referred to by what it is for, so a seeded page reads as
     * the thing it is demonstrating; the rest are named by chance.
     */
    private function block(
        ObjectManager $manager,
        int $from,
        int $to,
        MembershipTypes $type,
        ?string $firstName = null,
        bool $lapsedShare = false,
        bool $hidden = false,
        bool $deleted = false,
    ): void {
        foreach (
            range(
                $from,
                $to,
            ) as $lidnr
        ) {
            // Every third graduate has let their membership lapse, so both a graduate who still renews and one who
            // stopped are on the books.
            $lapsed = $lapsedShare && 0 === $lidnr % 3;

            $member = $this->member(
                $lidnr,
                $type,
                $firstName ?? self::pick(
                    self::FIRST_NAMES,
                    $lidnr,
                ),
                $lapsed,
                $hidden,
                $deleted,
            );

            $manager->persist($member);

            // Named for the ledger fixtures that decide things about these people. The projection has references of
            // its own, handed out after the replay by
            // {@see \App\DataFixtures\Decision\ProjectionReferenceFixture}, which is why these carry a prefix.
            $this->addReference(
                sprintf(
                    'ledger-member-%d',
                    $lidnr,
                ),
                $member,
            );
        }

        // Flushed a block at a time: the whole population in one unit of work holds a few hundred members and their
        // whole histories in memory at once.
        $manager->flush();
    }

    private function member(
        int $lidnr,
        MembershipTypes $type,
        string $firstName,
        bool $lapsed,
        bool $hidden = false,
        bool $deleted = false,
    ): Member {
        $member = new Member();
        $member->setLidnr($lidnr);
        $member->setFirstName($firstName);
        $member->setMiddleName('');
        $member->setLastName(self::pick(self::LAST_NAMES, $lidnr * 7));
        $member->setInitials(implode(
            '.',
            array_map(
                static fn (string $part): string => mb_substr(
                    $part,
                    0,
                    1,
                ),
                explode(
                    ' ',
                    $firstName,
                ),
            ),
        ) . '.');

        $member->setEmail(sprintf(
            '%d@example.com',
            $lidnr,
        ));
        $member->setBirth(in_array(
            $lidnr,
            self::BORN_TODAY,
            true,
        )
            ? new DateTime()->sub(new DateInterval('P21Y'))
            : new DateTime(sprintf(
                '%d-%02d-%02d',
                1975 + $lidnr % 30,
                1 + $lidnr % 12,
                1 + $lidnr % 28,
            )));

        $member->setStudy(MembershipTypes::External === $type ? Studies::Other : Studies::BAM);
        $member->setStudentNumber(sprintf(
            '1%06d',
            $lidnr,
        ));
        $member->setChangedOn(new DateTime());
        $member->setHidden($hidden);
        $member->setDeleted($deleted);
        $member->setSupremum('nee');
        $member->setAuthenticationKey($lapsed ? null : hash('sha256', (string) $lidnr));

        $this->chain(
            $member,
            $lidnr,
            $type,
            $lapsed,
        );

        // What is left of somebody who has been removed is their name and the decisions that named them. Their
        // address, the mail they were reachable at and the key they signed in with are what "removed" means, so they
        // are not written in the first place rather than written and then cleared.
        if ($deleted) {
            $member->setEmail(null);
            $member->setAuthenticationKey(null);

            return $member;
        }

        $this->addresses(
            $member,
            $lidnr,
        );

        return $member;
    }

    /**
     * A home address for everybody, and a student address for the one member who is only reachable at one.
     */
    private function addresses(
        Member $member,
        int $lidnr,
    ): void {
        $home = new Address();
        $home->setType(AddressTypes::Home);
        $home->setStreet(self::pick(self::STREETS, $lidnr));
        $home->setNumber((string) (1 + $lidnr % 200));
        // Four digits and two letters, which is what a postcode is in the country these addresses say they are in.
        $home->setPostalCode(sprintf(
            '%d %s%s',
            5600 + $lidnr % 100,
            chr(65 + $lidnr % 26),
            chr(65 + ($lidnr * 3) % 26),
        ));
        $home->setCity(self::pick(self::CITIES, $lidnr));
        $home->setCountry(PostalRegions::Netherlands);
        $home->setPhone('1');
        $member->setHomeAddress($home);

        if (self::STUDENT_ADDRESS_ONLY !== $lidnr) {
            return;
        }

        $member->setEmail('student@student.tue.nl');

        $student = new Address();
        $student->setType(AddressTypes::Student);
        $student->setStreet('Groene Loper');
        $student->setNumber('5');
        $student->setPostalCode('5612 AE');
        $student->setCity('Eindhoven');
        $student->setCountry(PostalRegions::Netherlands);
        $student->setPhone('1');
        $member->setStudentAddress($student);
    }

    /**
     * A membership for every association year the member has been one, which is what the projection reads their type
     * and their expiry off. Somebody who has lapsed stops renewing two years ago; everybody else runs to the year in
     * progress.
     */
    private function chain(
        Member $member,
        int $lidnr,
        MembershipTypes $type,
        bool $lapsed,
    ): void {
        // Nobody starts out a graduate: they were an ordinary member while they studied and became one on finishing,
        // which is what lets a graduate have been an ordinary member of a body before they were an inactive one. A
        // graduate is given a longer history for that reason, and switches type two years back.
        $graduating = MembershipTypes::Graduate === $type;
        $years = $graduating
            ? 6 + $lidnr % 3
            : 1 + $lidnr % 6;

        $start = new DateTime(sprintf(
            '%d-08-15 midnight',
            (int) $this->now->format('Y') - $years,
        ));
        $until = $lapsed
            ? new DateTime()->sub(new DateInterval('P2Y'))
            : $this->now;

        // Measured back from where the history ends rather than from today, so somebody who stopped renewing years
        // ago still finished as a graduate. Fixed at two years ago, a lapsed graduate never reached the switch and
        // their last membership said they were an ordinary member.
        $switchOn = new DateTime($until->format('Y-m-d'))->sub(new DateInterval('P1Y'));

        while ($start < $until) {
            $membership = new Membership(
                member: $member,
                type: $graduating && $start < $switchOn ? MembershipTypes::Ordinary : $type,
                startDate: clone $start,
                endDate: null,
            );
            $member->addMembership($membership);

            $start = $membership->getEndDate();
        }
    }

    /**
     * One entry out of a list, chosen by a number rather than by chance, so a rerun of the seed says the same thing.
     *
     * @param string[] $pool
     */
    private static function pick(
        array $pool,
        int $at,
    ): string {
        return $pool[$at % count($pool)];
    }

    /**
     * @return array<array-key, class-string<Fixture>>
     */
    #[Override]
    public function getDependencies(): array
    {
        // The members named one by one are numbered by the sequence, so they are written before the generator is
        // dropped for the numbered population below.
        return [MemberFixture::class];
    }

    /**
     * @return string[]
     */
    #[Override]
    public static function getGroups(): array
    {
        return ['ledger'];
    }
}
