<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\GameSessionPlayerRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Gestion des utilisateurs (réservé ROLE_ADMIN via security.yaml : ^/api/admin).
 *
 * Tout le monde est JOUEUR par défaut ; un administrateur possède en plus
 * ROLE_ADMIN. Le super-admin (email configuré) est toujours administrateur et
 * ne peut être ni rétrogradé ni supprimé ; on ne peut pas non plus se
 * rétrograder / se supprimer soi-même (garde anti-verrouillage).
 */
#[Route('/api/admin/users')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly GameSessionPlayerRepository $players,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
        private readonly Connection $db,
        private readonly string $superAdminEmail,
    ) {
    }

    #[Route('', name: 'api_admin_users', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $users = $this->users->findBy([], ['createdAt' => 'ASC']);

        return $this->json(array_map($this->serialize(...), $users));
    }

    #[Route('', name: 'api_admin_user_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $email = trim((string) ($data['email'] ?? ''));
        $pseudo = trim((string) ($data['pseudo'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if (\strlen($password) < 6) {
            return $this->json(['error' => 'Le mot de passe doit faire au moins 6 caractères.'], 422);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setPseudo($pseudo);
        // Par défaut JOUEUR (roles vides) ; ADMIN seulement si demandé.
        $user->setRoles($this->rolesFor($email, (bool) ($data['isAdmin'] ?? false)));

        if ($invalid = $this->validationErrors($user)) {
            return $invalid;
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        return $this->json($this->serialize($user), 201);
    }

    #[Route('/{id}', name: 'api_admin_user_update', methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->users->find($id);
        if (!$user) {
            return $this->json(['error' => 'Utilisateur introuvable.'], 404);
        }
        $data = json_decode($request->getContent(), true) ?? [];

        if (isset($data['pseudo'])) {
            $user->setPseudo(trim((string) $data['pseudo']));
        }

        // Changement de rôle admin : bloqué pour le super-admin et pour soi-même.
        if (\array_key_exists('isAdmin', $data)) {
            $wantAdmin = (bool) $data['isAdmin'];
            if (!$wantAdmin && $this->isSuperAdmin($user)) {
                return $this->json(['error' => 'Le super-administrateur ne peut pas être rétrogradé.'], 403);
            }
            if (!$wantAdmin && $this->isSelf($user)) {
                return $this->json(['error' => 'Vous ne pouvez pas retirer votre propre rôle administrateur.'], 403);
            }
            $user->setRoles($this->rolesFor($user->getEmail(), $wantAdmin));
        }

        if (!empty($data['password'])) {
            if (\strlen((string) $data['password']) < 6) {
                return $this->json(['error' => 'Le mot de passe doit faire au moins 6 caractères.'], 422);
            }
            $user->setPassword($this->hasher->hashPassword($user, (string) $data['password']));
        }

        if ($invalid = $this->validationErrors($user)) {
            return $invalid;
        }

        $this->em->flush();

        return $this->json($this->serialize($user));
    }

    #[Route('/{id}', name: 'api_admin_user_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->users->find($id);
        if (!$user) {
            return $this->json(['error' => 'Utilisateur introuvable.'], 404);
        }
        if ($this->isSuperAdmin($user)) {
            return $this->json(['error' => 'Le super-administrateur ne peut pas être supprimé.'], 403);
        }
        if ($this->isSelf($user)) {
            return $this->json(['error' => 'Vous ne pouvez pas supprimer votre propre compte.'], 403);
        }

        // Les refresh tokens sont liés par email (pas de clé étrangère) : on les
        // purge. L'historique de parties (GameSessionPlayer) est supprimé en
        // cascade au niveau base (onDelete: CASCADE).
        $this->db->executeStatement('DELETE FROM refresh_tokens WHERE username = ?', [$user->getEmail()]);
        $this->em->remove($user);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * Rôles stockés pour un compte : le super-admin est toujours ADMIN ;
     * sinon ADMIN si demandé, sinon [] (JOUEUR par défaut, ajouté à la volée
     * par User::getRoles()).
     *
     * @return list<string>
     */
    private function rolesFor(?string $email, bool $wantAdmin): array
    {
        $isAdmin = $wantAdmin || strcasecmp((string) $email, $this->superAdminEmail) === 0;

        return $isAdmin ? [User::ROLE_ADMIN] : [];
    }

    private function isSuperAdmin(User $user): bool
    {
        return strcasecmp((string) $user->getEmail(), $this->superAdminEmail) === 0;
    }

    private function isSelf(User $user): bool
    {
        return $this->getUser() instanceof User && $this->getUser()->getId() === $user->getId();
    }

    private function validationErrors(User $user): ?JsonResponse
    {
        $errors = $this->validator->validate($user);
        if (\count($errors) === 0) {
            return null;
        }
        $messages = [];
        foreach ($errors as $error) {
            $messages[$error->getPropertyPath()] = $error->getMessage();
        }

        return $this->json(['error' => 'Données invalides.', 'fields' => $messages], 422);
    }

    private function serialize(User $u): array
    {
        return [
            'id' => $u->getId(),
            'email' => $u->getEmail(),
            'pseudo' => $u->getPseudo(),
            'roles' => $u->getRoles(),
            'isAdmin' => $u->isAdmin(),
            'isSuperAdmin' => $this->isSuperAdmin($u),
            'isSelf' => $this->isSelf($u),
            'createdAt' => $u->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'stats' => $this->players->statsForUser($u),
        ];
    }
}
