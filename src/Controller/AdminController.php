<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Contrôleur d'administration - Gestion des utilisateurs
 * 
 * Accessible uniquement par ROLE_ADMIN (somafi_admin)
 * Phase B du plan d'implémentation
 */
#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    /**
     * Rôles disponibles pour l'attribution
     * Groupés par catégorie pour l'affichage dans le formulaire
     */
    private const AVAILABLE_ROLES = [
        'Global' => [
            'ROLE_ADMIN' => 'Administrateur global',
            'ROLE_ADMIN_AGENCE' => 'Admin agence',
            'ROLE_USER_AGENCE' => 'Utilisateur agence',
        ],
        'Supplémentaire' => [
            'ROLE_EDIT' => 'Modification équipements',
            'ROLE_DELETE' => 'Archivage équipements',
        ],
        'Contrat Cadre' => [
            'ROLE_CLIENT_CC' => 'Client contrat cadre (lecture)',
            'ROLE_ADMIN_CC' => 'Admin contrat cadre (employé SOMAFI)',
        ],
    ];

    /**
     * Liste des 13 agences SOMAFI
     */
    private const AGENCIES = [
        'S10', 'S40', 'S50', 'S60', 'S70', 'S80',
        'S100', 'S120', 'S130', 'S140', 'S150', 'S160', 'S170',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    // =========================================================================
    // B.1 + B.2 — Liste des utilisateurs
    // =========================================================================

    #[Route('/users', name: 'admin_users', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $repo = $this->em->getRepository(User::class);

        // Filtres
        $filterRole = $request->query->get('role', '');
        $filterAgency = $request->query->get('agency', '');
        $filterStatus = $request->query->get('status', ''); // active, inactive
        $search = $request->query->get('q', '');

        // Construction de la requête
        $qb = $repo->createQueryBuilder('u')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC');

        if ($filterStatus === 'active') {
            $qb->andWhere('u.isActive = :active')->setParameter('active', true);
        } elseif ($filterStatus === 'inactive') {
            $qb->andWhere('u.isActive = :active')->setParameter('active', false);
        }

        if (!empty($filterRole)) {
            // JSON_CONTAINS pour le champ roles (JSON)
            $qb->andWhere('u.roles LIKE :role')
               ->setParameter('role', '%"' . $filterRole . '"%');
        }

        if (!empty($filterAgency)) {
            // JSON_CONTAINS pour le champ agencies (JSON)
            $qb->andWhere('u.agencies LIKE :agency')
               ->setParameter('agency', '%"' . $filterAgency . '"%');
        }

        if (!empty($search)) {
            $qb->andWhere('u.nom LIKE :search OR u.prenom LIKE :search OR u.email LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $users = $qb->getQuery()->getResult();

        // Charger les contrats cadre pour le dropdown du filtre
        $contratsCadre = $this->em->getRepository(\App\Entity\ContratCadre::class)
            ->findBy(['isActive' => true], ['nom' => 'ASC']);

        return $this->render('admin/users/index.html.twig', [
            'users' => $users,
            'available_roles' => self::AVAILABLE_ROLES,
            'agencies' => self::AGENCIES,
            'contrats_cadre' => $contratsCadre,
            'filter_role' => $filterRole,
            'filter_agency' => $filterAgency,
            'filter_status' => $filterStatus,
            'search' => $search,
            'total_users' => count($users),
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

        // Charger les contrats cadre
        $contratsCadre = $this->em->getRepository(\App\Entity\ContratCadre::class)
            ->findBy(['isActive' => true], ['nom' => 'ASC']);

        return $this->render('admin/users/form.html.twig', [
            'user' => null,
            'is_edit' => false,
            'available_roles' => self::AVAILABLE_ROLES,
            'agencies' => self::AGENCIES,
            'contrats_cadre' => $contratsCadre,
            'errors' => [],
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

        $contratsCadre = $this->em->getRepository(\App\Entity\ContratCadre::class)
            ->findBy(['isActive' => true], ['nom' => 'ASC']);

        return $this->render('admin/users/form.html.twig', [
            'user' => $user,
            'is_edit' => true,
            'available_roles' => self::AVAILABLE_ROLES,
            'agencies' => self::AGENCIES,
            'contrats_cadre' => $contratsCadre,
            'errors' => [],
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
                $newPassword = $request->request->get('new_password', '');
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
            'user' => $user,
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

        // Protection CSRF
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('user_toggle_' . $id, $token)) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_users');
        }

        // Empêcher la désactivation de son propre compte
        if ($user->getId() === $this->getUser()->getId()) {
            $this->addFlash('error', 'Vous ne pouvez pas désactiver votre propre compte.');
            return $this->redirectToRoute('admin_users');
        }

        $user->setIsActive(!$user->isActive());
        $user->setUpdatedAt(new \DateTime());
        $this->em->flush();

        $status = $user->isActive() ? 'activé' : 'désactivé';
        $this->addFlash('success', sprintf(
            'Utilisateur %s %s.',
            $user->getFullName(),
            $status
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

        // Protection CSRF
        $tokenId = $isNew ? 'user_new' : 'user_edit_' . $user->getId();
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid($tokenId, $token)) {
            $errors[] = 'Token CSRF invalide.';
        }

        // Récupération des données
        $email = trim($request->request->get('email', ''));
        $nom = trim($request->request->get('nom', ''));
        $prenom = trim($request->request->get('prenom', ''));
        $roles = $request->request->all('roles') ?: [];
        $agencies = $request->request->all('agencies') ?: [];
        $contratCadreId = $request->request->get('contrat_cadre_id', '');
        $isActive = $request->request->getBoolean('is_active', true);

        // Validation email
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email invalide.';
        }

        // Vérifier unicité email (sauf pour l'utilisateur en cours d'édition)
        if (!empty($email)) {
            $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existing && (!$user->getId() || $existing->getId() !== $user->getId())) {
                $errors[] = 'Cet email est déjà utilisé.';
            }
        }

        // Validation nom/prénom
        if (empty($nom)) {
            $errors[] = 'Le nom est obligatoire.';
        }
        if (empty($prenom)) {
            $errors[] = 'Le prénom est obligatoire.';
        }

        // Validation rôles
        $allRoles = [];
        foreach (self::AVAILABLE_ROLES as $group) {
            $allRoles = array_merge($allRoles, array_keys($group));
        }
        $roles = array_intersect($roles, $allRoles); // Whitelist
        if (empty($roles)) {
            $errors[] = 'Au moins un rôle est requis.';
        }

        // Validation agences (whitelist)
        $agencies = array_intersect($agencies, self::AGENCIES);

        // Validation mot de passe (uniquement à la création)
        if ($isNew) {
            $password = $request->request->get('password', '');
            if (strlen($password) < 8) {
                $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
            }
        }

        // Contrat cadre (si ROLE_CLIENT_CC sélectionné)
        $contratCadre = null;
        if (in_array('ROLE_CLIENT_CC', $roles) || in_array('ROLE_ADMIN_CC', $roles)) {
            if (!empty($contratCadreId)) {
                $contratCadre = $this->em->getRepository(\App\Entity\ContratCadre::class)
                    ->find((int) $contratCadreId);
            }
        }

        if (!empty($errors)) {
            $contratsCadre = $this->em->getRepository(\App\Entity\ContratCadre::class)
                ->findBy(['isActive' => true], ['nom' => 'ASC']);

            // Pré-remplir les valeurs du formulaire pour ne pas les perdre
            $formUser = $isNew ? new User() : $user;
            $formUser->setEmail($email);
            $formUser->setNom($nom);
            $formUser->setPrenom($prenom);
            $formUser->setRoles($roles);
            $formUser->setAgencies($agencies);
            $formUser->setIsActive($isActive);
            $formUser->setContratCadre($contratCadre);

            return $this->render('admin/users/form.html.twig', [
                'user' => $isNew ? $formUser : $user,
                'is_edit' => !$isNew,
                'available_roles' => self::AVAILABLE_ROLES,
                'agencies' => self::AGENCIES,
                'contrats_cadre' => $contratsCadre,
                'errors' => $errors,
            ]);
        }

        // Appliquer les modifications
        $user->setEmail($email);
        $user->setNom($nom);
        $user->setPrenom($prenom);
        $user->setRoles($roles);
        $user->setAgencies(!empty($agencies) ? array_values($agencies) : null);
        $user->setIsActive($isActive);
        $user->setContratCadre($contratCadre);
        $user->setUpdatedAt(new \DateTime());

        if ($isNew) {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
            $user->setPassword($hashedPassword);
            $this->em->persist($user);
        }

        $this->em->flush();

        $action = $isNew ? 'créé' : 'modifié';
        $this->addFlash('success', sprintf(
            'Utilisateur %s %s avec succès.',
            $user->getFullName(),
            $action
        ));

        return $this->redirectToRoute('admin_users');
    }
}
