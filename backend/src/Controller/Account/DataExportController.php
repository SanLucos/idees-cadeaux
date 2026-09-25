<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\DataExport;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Exception\HiddenResourceException;
use App\Message\ExportUserData;
use App\Repository\DataExportRepository;
use App\Repository\UserRepository;
use App\Security\Reauthentication;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * "Export de mes données" (spec §5.13, §11 décision 24): asked with a
 * re-authentication, built asynchronously, link by email valid 48 h.
 * A manager exports a child profile the same way (the link comes to
 * them). Always as the signed-in adult, never with X-Acting-As.
 */
final class DataExportController
{
    public function __construct(
        private readonly DataExportRepository $exports,
        private readonly UserRepository $users,
        private readonly Reauthentication $reauthentication,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'limiter.data_export')]
        private readonly RateLimiterFactory $limiter,
        #[Autowire(service: 'default.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

    #[Route('/api/account/export', name: 'account_export', methods: ['POST'])]
    public function exportMine(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        return $this->request($me, $me, $request);
    }

    #[Route('/api/managed-profiles/{id}/export', name: 'managed_profiles_export', methods: ['POST'])]
    public function exportChild(string $id, Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $child = Uuid::isValid($id) ? $this->users->find($id) : null;
        if (null === $child || !$child->isManagedBy($me)) {
            throw new HiddenResourceException();
        }

        return $this->request($child, $me, $request);
    }

    /** The emailed link: no sign-in, the token is the key (spec §5.13). */
    #[Route('/exports/{id}/{token}', name: 'data_export_download', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function download(string $id, string $token): Response
    {
        $export = $this->exports->find($id);
        $gone = new Response('', 404, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
        if (null === $export || !$export->isDownloadable($token) || null === $export->getPath()) {
            return $gone;
        }

        try {
            $stream = $this->storage->readStream($export->getPath());
        } catch (FilesystemException) {
            return $gone;
        }

        $name = \sprintf('export-%s.zip', $export->getCreatedAt()->format('Y-m-d'));

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function request(User $subject, User $requester, Request $request): JsonResponse
    {
        $this->reauthentication->check($requester, AccountController::body($request));

        $export = $this->exports->findPendingFor($subject);
        if (null === $export) {
            if (!$this->limiter->create($requester->getId()->toRfc4122())->consume()->isAccepted()) {
                throw new ApiProblemException('export.rate_limited', 'Too many exports, try again tomorrow.', 429);
            }
            $export = new DataExport($subject, $requester);
            $this->em->persist($export);
            $this->em->flush();
            $this->bus->dispatch(new ExportUserData($export->getId()->toRfc4122()));
        }

        return new JsonResponse(['id' => $export->getId()->toRfc4122(), 'status' => $export->getStatus()], 202);
    }
}
