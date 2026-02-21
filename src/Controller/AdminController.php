<?php

namespace App\Controller;

use App\Entity\ContratCadre;
use App\Entity\User;
use App\Entity\UserContratCadre;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Contrôleur d'administration - Gestion des utilisateurs
 *
 * Accessible uniquement par ROLE_ADMIN
 * La gestion des droits CC (admin / user) passe désormais par la table
 * user_contrat_cadre — plus de ROLE_CLIENT_CC, ROLE_ADMIN_CC,
 * ni de rôles {SLUG}_ADMIN / {SLUG}_USER dans le JSON roles.
 */
#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    /**
     * Rôles disponibles dans le formulaire utilisateur.
     * SUPPRIMÉS : ROLE_CLIENT_CC, ROLE_ADMIN_CC, et tous les {SLUG}_ADMIN / {SLUG}_USER.
     * Les droits CC sont gérés via les cards CC Admin / CC User du formulaire.
     */
    private const BASE_ROLES = [
        'Global' => [
            'ROLE_ADMIN'        => 'Administrateur global',
            'ROLE_ADMIN_AGENCE' => 'Admin agence',
            'ROLE_USER_AGENCE'  => 'Utilisateur agence',
        ],
        'Supplémentaire' => [
            'ROLE_EDIT'                           => 'Modification équipements',
            'ROLE_DELETE'                         => 'Archivage équipements',
            'ROLE_GESTIONNAIRE_CONTRAT_ENTRETIEN' => 'Gestionnaire contrats entretien',
        ],
    ];

    private const AGENCIES = [
        'S10', 'S40', 'S50', 'S60', 'S70', 'S80',
        'S100', 'S120', 'S130', 'S140', 'S150', 'S160', 'S170',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    // =========================================================================
    // Helpers privés
    // =========================================================================

    /**
     * Retourne les rôles disponibles (statiques uniquement, plus de génération dynamique CC).
     *
     * @return array<string, array<string, string>>
     */
    private function getAvailableRoles(): array
    {
        return self::BASE_ROLES;
    }

    /**
     * Liste plate des rôles autorisés pour la whitelist POST.
     *
     * @return string[]
     */
    private function getAllAllowedRoles(): array
    {
        $allRoles = [];
        foreach (self::BASE_ROLES as $group) {
            $allRoles = array_merge($allRoles, array_keys($group));
        }
        return $allRoles;
    }

    /**
     * Charge tous les ContratCadre actifs indexés par id.
     * Utilisé par handleUserForm pour éviter N requêtes.
     *
     * @return array<int, ContratCadre>
     */
    private function loadAllCcById(): array
    {
        $ccs = $this->em->getRepository(ContratCadre::class)
            ->findBy(['isActive' => true], ['nom' => 'ASC']);

        $indexed = [];
        foreach ($ccs as $cc) {
            $indexed[$cc->getId()] = $cc;
        }
        return $indexed;
    }

    // =========================================================================
    // B.1 — Liste des utilisateurs
    // =========================================================================

    #[Route('/users', name: 'admin_users', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $repo = $this->em->getRepository(User::class);

        $filterRole   = $request->query->get('role', '');
        $filterAgency = $request->query->get('agency', '');
        $filterStatus = $request->query->get('status', '');
        $search       = $request->query->get('q', '');

        $qb = $repo->createQueryBuilder('u')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC');

        if ($filterStatus === 'active') {
            $qb->andWhere('u.isActive = :active')->setParameter('active', true);
        } elseif ($filterStatus === 'inactive') {
            $qb->andWhere('u.isActive = :active')->setParameter('active', false);
        }

        if (!empty($filterRole)) {
            $qb->andWhere('u.roles LIKE :role')
               ->setParameter('role', '%"' . $filterRole . '"%');
        }

        if (!empty($filterAgency)) {
            $qb->andWhere('u.agencies LIKE :agency')
               ->setParameter('agency', '%"' . $filterAgency . '"%');
        }

        if (!empty($search)) {
            $qb->andWhere('u.nom LIKE :search OR u.prenom LIKE :search OR u.email LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $users = $qb->getQuery()->getResult();

        $contratsCadre = $this->em->getRepository(ContratCadre::class)
            ->findBy(['isActive' => true], ['nom' => 'ASC']);

        return $this->render('admin/users/index.html.twig', [
            'users'           => $users,
            'available_roles' => $this->getAvailableRoles(),
            'agencies'        => self::AGENCIES,
            'contrats_cadre'  => $contratsCadre,
            'filter_role'     => $filterRole,
            'filter_agency'   => $filterAgency,
            'filter_status'   => $filterStatus,
            'search'          => $search,
            'total_users'     => count($users),
        ]);
    }

    // =========================================================================
    // B.3 — Création d'un utilisateur
    // =========================================================================

    #[Route('/users/new', name: 'admin_users_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ValidatorInterface $validator): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleUserForm($request, $validator, new User(), true);
        }

        return $this->render('admin/users/form.html.twig', [
            'user'            => null,
            'is_edit'         => false,
            'available_roles' => $this->getAvailableRoles(),
            'agencies'        => self::AGENCIES,
            'contrats_cadre'  => array_values($this->loadAllCcById()),
            'errors'          => [],
        ]);
    }

    // =========================================================================
    // B.4 — Édition d'un utilisateur
    // =========================================================================

    #[Route('/users/{id}/edit', name: 'admin_users_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, ValidatorInterface $validator): Response
    {
        $user = $this->em->getRepository(User::class)->find($id);

        if (!$user) {
            throw $this->createNotFoundException('Utilisateur non trouvé');
        }

        if ($request->isMethod('POST')) {
            return $this->handleUserForm($request, $validator, $user, false);
        }

        return $this->render('admin/users/form.html.twig', [
            'user'            => $user,
            'is_edit'         => true,
            'available_roles' => $this->getAvailableRoles(),
            'agencies'        => self::AGENCIES,
            'contrats_cadre'  => array_values($this->loadAllCcById()),
            'errors'          => [],
        ]);
    }

    // =========================================================================
    // B.5 — Changement de mot de passe
    // =========================================================================

    #[Route('/users/{id}/password', name: 'admin_users_password', methods: ['GET', 'POST'])]
    public function password(int $id, Request $request): Response
    {
        $user = $this->em->getRepository(User::class)->find($id);

        if (!$user) {
            throw $this->createNotFoundException('Utilisateur non trouvé');
        }

        $errors = [];

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('user_password_' . $id, $token)) {
                $errors[] = 'Token CSRF invalide.';
            } else {
                $newPassword     = $request->request->get('new_password', '');
                $confirmPassword = $request->request->get('confirm_password', '');

                if (strlen($newPassword) < 8) {
                    $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
                }

                if ($newPassword !== $confirmPassword) {
                    $errors[] = 'Les mots de passe ne correspondent pas.';
                }

                if (empty($errors)) {
                    $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
                    $user->setPassword($hashedPassword);
                    $user->setUpdatedAt(new \DateTime());
                    $this->em->flush();

                    $this->addFlash('success', sprintf(
                        'Mot de passe de %s modifié avec succès.',
                        $user->getFullName()
                    ));

                    return $this->redirectToRoute('admin_users');
                }
            }
        }

        return $this->render('admin/users/password.html.twig', [
            'user'   => $user,
            'errors' => $errors,
        ]);
    }

    // =========================================================================
    // B.6 — Toggle activation/désactivation
    // =========================================================================

    #[Route('/users/{id}/toggle', name: 'admin_users_toggle', methods: ['POST'])]
    public function toggle(int $id, Request $request): Response
    {
        $user = $this->em->getRepository(User::class)->find($id);

        if (!$user) {
            throw $this->createNotFoundException('Utilisateur non trouvé');
        }

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('user_toggle_' . $id, $token)) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_users');
        }

        if ($user->getUserIdentifier() === $this->getUser()->getUserIdentifier()) {
            $this->addFlash('error', 'Vous ne pouvez pas désactiver votre propre compte.');
            return $this->redirectToRoute('admin_users');
        }

        $user->setIsActive(!$user->isActive());
        $user->setUpdatedAt(new \DateTime());
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Utilisateur %s %s.',
            $user->getFullName(),
            $user->isActive() ? 'activé' : 'désactivé'
        ));

        return $this->redirectToRoute('admin_users');
    }

    // =========================================================================
    // Méthode privée : traitement du formulaire (new + edit)
    // =========================================================================

    private function handleUserForm(
        Request $request,
        ValidatorInterface $validator,
        User $user,
        bool $isNew
    ): Response {
        $errors = [];

        // --- CSRF ---
        $tokenId = $isNew ? 'user_new' : 'user_edit_' . $user->getId();
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            $errors[] = 'Token CSRF invalide.';
        }

        // --- Données de base ---
        $email    = trim($request->request->get('email', ''));
        $nom      = trim($request->request->get('nom', ''));
        $prenom   = trim($request->request->get('prenom', ''));
        $roles    = $request->request->all('roles') ?: [];
        $agencies = $request->request->all('agencies') ?: [];
        $isActive = $request->request->getBoolean('is_active', true);

        // --- Données CC (nouvelles) ---
        // cc_admin[] : IDs des CCs où l'utilisateur est admin SOMAFI
        // cc_user[]  : IDs des CCs où l'utilisateur est client (lecture)
        $ccAdminIds = array_map('intval', $request->request->all('cc_admin') ?: []);
        $ccUserIds  = array_map('intval', $request->request->all('cc_user')  ?: []);

        // --- Validations ---
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email invalide.';
        }

        if (!empty($email)) {
            $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existing && (!$user->getId() || $existing->getId() !== $user->getId())) {
                $errors[] = 'Cet email est déjà utilisé.';
            }
        }

        if (empty($nom)) {
            $errors[] = 'Le nom est obligatoire.';
        }
        if (empty($prenom)) {
            $errors[] = 'Le prénom est obligatoire.';
        }

        // Whitelist des rôles (uniquement BASE_ROLES — plus de rôles CC dans le JSON)
        $roles = array_values(array_intersect($roles, $this->getAllAllowedRoles()));

        // Règle de validation :
        // - Un utilisateur SOMAFI (cc_admin ou rôle global) doit avoir au moins un rôle global.
        // - Un client externe CC (cc_user uniquement, sans rôle global ni cc_admin) est valide
        //   sans rôle global : il n'accède qu'au portail CC, pas à l'appli SOMAFI.
        $isExternalCcUser = empty($roles) && empty($ccAdminIds) && !empty($ccUserIds);
        if (empty($roles) && !$isExternalCcUser) {
            $errors[] = 'Au moins un rôle est requis pour un utilisateur SOMAFI.';
        }

        // Whitelist des agences
        $agencies = array_values(array_intersect($agencies, self::AGENCIES));

        // Mot de passe (création uniquement)
        $password = '';
        if ($isNew) {
            $password = $request->request->get('password', '');
            if (strlen($password) < 8) {
                $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
            }
        }

        // --- Chargement des CCs en une seule requête ---
        $allCcById = $this->loadAllCcById();

        // --- Retour formulaire si erreurs ---
        if (!empty($errors)) {
            // Pré-remplir l'objet pour conserver les saisies (édition : on ne touche pas l'entité en BDD)
            $formUser = $isNew ? new User() : clone $user;
            $formUser->setEmail($email);
            $formUser->setNom($nom);
            $formUser->setPrenom($prenom);
            $formUser->setRoles($roles);
            $formUser->setAgencies($agencies ?: null);
            $formUser->setIsActive($isActive);
            // On NE sync pas les CC sur un clone/new : le template relira cc_admin[]/cc_user[]
            // depuis les checkboxes cochées (Twig regarde user.userContratCadres pour le mode edit)

            return $this->render('admin/users/form.html.twig', [
                'user'            => $isNew ? $formUser : $user,
                'is_edit'         => !$isNew,
                'available_roles' => $this->getAvailableRoles(),
                'agencies'        => self::AGENCIES,
                'contrats_cadre'  => array_values($allCcById),
                'errors'          => $errors,
                // On repasse les IDs cochés pour que Twig puisse re-cocher les cases après erreur
                'cc_admin_ids'    => $ccAdminIds,
                'cc_user_ids'     => $ccUserIds,
            ]);
        }

        // --- Application des modifications ---
        $user->setEmail($email);
        $user->setNom($nom);
        $user->setPrenom($prenom);
        $user->setRoles($roles);
        $user->setAgencies(!empty($agencies) ? $agencies : null);
        $user->setIsActive($isActive);
        $user->setUpdatedAt(new \DateTime());

        // Synchronisation des associations CC (vide + recrée proprement)
        $user->syncContratCadres($ccAdminIds, $ccUserIds, $allCcById);

        if ($isNew) {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
            $user->setPassword($hashedPassword);
            $this->em->persist($user);
        }

        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Utilisateur %s %s avec succès.',
            $user->getFullName(),
            $isNew ? 'créé' : 'modifié'
        ));

        return $this->redirectToRoute('admin_users');
    }
}