<?php


namespace App\Controller;

use App\Entity\Timesheet;
use App\Entity\User;
use App\Export\ServiceExport;
use App\Model\UserVacation\VacationMonth;
use App\Model\UserVacation\VacationYear;
use App\Repository\TimesheetRepository;
use App\Repository\UserInvoiceRepository;
use App\Export\UserInvoice\XlsxRenderer;
use App\Repository\UserRepository;
use DateTime;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/uservacation')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[IsGranted ('view_activity')]
class VacationController extends AbstractController
{

    private $renderer;
    private $logger;

    /**
     * @param TimesheetRepository $timesheet
     * @param ServiceExport $export
     */
    public function __construct(private TimesheetRepository $timesheetRepository,
                                private UserRepository $userRepository,
                                XlsxRenderer $renderer,
                                LoggerInterface $logger = null,)
    {
        $this->renderer = $renderer;
        $this->logger = $logger;
    }

    /**
     *
     * @param Request $request
     * @return Response
     */
    #[Route(path: '/', name: 'vacation_user_admin', methods: ['GET'])]
    #[IsGranted('view_other_timesheet')]
    public function indexAction(Request $request): Response
    {
        $formUsername = $request->get('form')['username'];
        $year = $request->get('form')['year'];

        //$this->logger->info("" + $formUsername);

        $years = [];
        $yearsGlobal = [];
        if ($year != null) {
            $startDate = DateTime::createFromFormat('Y-m-d H:i:s', intval($year).'-01-01 00:00:00');
            $endDate = DateTime::createFromFormat('Y-m-d H:i:s', intval($year).'-12-31 23:59:59');
        }
        else {
            $startDate = DateTime::createFromFormat('Y-m-d H:i:s', '2021-01-01 00:00:00');
            $endDate = null;
        }
        $allUsers = [];
        $allUsernames = array('simon', 'pierre', 'julien');
        if ($formUsername == null) {
            $selectedUsers = $allUsernames;
        }
        else {
            $selectedUsers = [$formUsername];
        }
        foreach ($allUsernames as $username) {
            $user = $this->userRepository->loadUserbyIdentifier($username);
            $allUsers[$username] = $user;
        }

        foreach ($allUsers as $user) {
            if (in_array($user->getUsername(), $selectedUsers)) {
                $this->getMonthlyActivity($years, 'vacation', 'Vacation', 'Vacation', $user, $startDate, $endDate);
                $this->getMonthlyActivity($years, 'rtt', 'Vacation','RTT', $user, $startDate, $endDate);
                $this->getMonthlyActivity($yearsGlobal, 'total', null, null, $user, $startDate, $endDate);
            }
        }
        krsort($years);

        for ($i=date("Y"); $i >= 2021 ; $i--) {
            $allYears[$i] = $i;
        }

        $form = $this->createFormBuilder()
            ->add('username', ChoiceType::class, ['choices' => $this->mkChoice($allUsers), 'data' => $formUsername])
            ->add('year', ChoiceType::class, ['choices' => $allYears, 'data' => $year])
            ->add('save', SubmitType::class, ['label' => 'Filter'])
            ->setMethod('GET')
            ->getForm();

        $viewVars = [
            'years' => $years,
            'allYears' => $allYears,
            'yearsGlobal' => $years,
            'allUsers' => $allUsers,
            'form' => $form
        ];

        return $this->render('uservacations/index.html.twig', $viewVars);
    }

    /**
     * Returns an array of Year statistics.
     *
     * @param User|null $user
     * @param DateTime|null $begin
     * @param DateTime|null $end
     * @return Year[]
     */
    public function getMonthlyActivity(&$years, $setter, $project = null, $activity = null, User $user = null, ?DateTime $begin = null, ?DateTime $end = null)
    {
        $qb = $this->timesheetRepository->createQueryBuilder('t');

        $qb->select('SUM(t.duration) as duration, MONTH(t.begin) as month, YEAR(t.begin) as year, DAY(t.begin) as day, user.alias as ualias')
            ->leftJoin('t.user', 'user')
            ->leftJoin('t.activity', 'activity')
            ->leftJoin('t.project', 'project')
        ;

//         $qb->andWhere()->eq('', 'Vacation');
//         $qb->andWhere()->eq('project.name', ('Vacation');
        if ($activity != null) {
            $qb->andWhere('activity.name = :activity')->setParameter('activity', $activity);
            $qb->andWhere('project.name = :project')->setParameter('project', $project);
        }
        $qb->andWhere('activity.name <> :publichd')->setParameter('publichd', 'Public holiday');
        $qb->andWhere('t.duration > 0');

        if (!empty($begin)) {
            $qb->andWhere($qb->expr()->gte('t.begin', ':from'))
                ->setParameter('from', $begin);
        } else {
            $qb->andWhere($qb->expr()->isNotNull('t.begin'));
        }

        if (!empty($end)) {
            $qb->andWhere($qb->expr()->lte('t.end', ':to'))
                ->setParameter('to', $end);
        } else {
            $qb->andWhere($qb->expr()->isNotNull('t.end'));
        }

        if (null !== $user) {
            $qb->andWhere('t.user = :user')
                ->setParameter('user', $user);
        }

        $qb
            ->orderBy('year', 'DESC')
            ->addOrderBy('month', 'DESC')
            ->addOrderBy('day', 'DESC')
            ->addOrderBy('ualias', 'ASC')

            ->groupBy('year')
            ->addGroupBy('month')
            ->addGroupBy('day')
            ->addGroupBy('ualias')
        ;



        $couldadd = true; // ($activity != null);

        foreach ($qb->getQuery()->execute() as $statRow) {
            $curYear = $statRow['year'];

            $year = $this->getYear($years, $curYear, $couldadd);

            if ($year == null) {
                continue;
            }
            $month = $year->getOrAddMonth($statRow['month'], $couldadd);
            if ($month == null) {
                continue;
            }
            $day = $month->getOrAddDay($statRow['day'], $couldadd);
            if ($day == null) {
                continue;
            }
            $user = $day->getOrAddUser($statRow['ualias'], $couldadd);
            if ($user != null) {
                $func = "set" . ucwords($setter);
                $user->$func($statRow['duration']);
                if ($activity == 'Vacation' || $activity == 'RTT') {
                    $year->sumVacationForUser($statRow['ualias'], $statRow['duration']);
                    $month->sumVacationForUser($statRow['ualias'], $statRow['duration']);
                }
            }
        }

    }

    /**
     * @param $years
     * @param $curYear
     * @param $activity
     * @return array
     */
    public function getYear(&$years, $curYear, $couldadd): ?VacationYear
    {
        if (isset($years[$curYear])) {
            return $years[$curYear];
        }
        if ($couldadd) {
            $year = new VacationYear($curYear);
            for ($i = 12; $i > 0; $i--) {
                $month = $i < 10 ? '0' . $i : (string)$i;
                $year->setMonth(new VacationMonth($month, $curYear));
            }
            $years[$curYear] = $year;
            return $year;
        }
        return null;
    }

    private function mkChoice(array $allUsers) {
        $result = [];
        foreach ($allUsers as $user) {
            $result[$user->getDisplayName()] = $user->getUsername();
        }
        return $result;
    }
}
