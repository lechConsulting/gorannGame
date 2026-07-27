<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\GameSessionPlayerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class AuthController extends AbstractController
{
    /**
     * Inscription d'un nouveau joueur. Le premier compte créé devient ADMIN.
     */
    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        ValidatorInterface $validator,
        string $superAdminEmail,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        $email = trim((string) ($data['email'] ?? ''));
        $pseudo = trim((string) ($data['pseudo'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($password === '' || \strlen($password) < 6) {
            return $this->json(['error' => 'Le mot de passe doit faire au moins 6 caractères.'], 422);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setPseudo($pseudo);

        // Par défaut, tout nouveau compte est JOUEUR (rôles vides ; ROLE_JOUEUR
        // est ajouté à la volée par User::getRoles()). Deviennent ADMIN : le
        // tout premier compte (amorçage) et le super-admin configuré.
        $isFirstUser = $em->getRepository(User::class)->count([]) === 0;
        $isSuperAdmin = strcasecmp($email, $superAdminEmail) === 0;
        $user->setRoles($isFirstUser || $isSuperAdmin ? [User::ROLE_ADMIN] : []);

        $errors = $validator->validate($user);
        if (\count($errors) > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[$error->getPropertyPath()] = $error->getMessage();
            }

            return $this->json(['error' => 'Données invalides.', 'fields' => $messages], 422);
        }

        $user->setPassword($hasher->hashPassword($user, $password));

        $em->persist($user);
        $em->flush();

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'pseudo' => $user->getPseudo(),
            'roles' => $user->getRoles(),
        ], 201);
    }

    /**
     * Point d'entrée du login. Le corps (email + password) est traité par le
     * firewall `json_login` qui renvoie un JWT ; ce contrôleur n'est jamais exécuté.
     */
    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return $this->json(['error' => 'Authentification échouée.'], 401);
    }

    /**
     * Profil de l'utilisateur connecté + statistiques.
     */
    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(GameSessionPlayerRepository $players): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'pseudo' => $user->getPseudo(),
            'roles' => $user->getRoles(),
            'isAdmin' => $user->isAdmin(),
            'stats' => $players->statsForUser($user),
        ]);
    }
}
