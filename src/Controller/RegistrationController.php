<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface as AddressException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        VerifyEmailHelperInterface $verifyEmailHelper,
        MailerInterface $mailer,
        \App\Service\Macro\MacroStateProvider $macroStates,
        \App\Service\Season\SeasonService $seasons,
        UserRepository $users,
        LoggerInterface $logger,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($request->isMethod('POST')) {
            $csrfToken = $request->request->getString('_csrf_token');
            if (!$this->isCsrfTokenValid('register_form', $csrfToken)) {
                $this->addFlash('error', 'Invalid security token.');
                return $this->redirectToRoute('app_register');
            }

            $email = (string) $request->request->get('email');
            $username = (string) $request->request->get('username');
            $plainPassword = (string) $request->request->get('password');

            if (empty($email) || empty($username) || empty($plainPassword)) {
                $this->addFlash('error', 'Please fill out all required fields, including your password.');
                return $this->redirectToRoute('app_register');
            }

            $problem = self::problemWith($email, $username);
            if ($problem === null && $users->findOneBy(['email' => $email]) !== null) {
                $problem = 'An account with that email already exists.';
            }
            if ($problem === null && $users->findOneBy(['username' => $username]) !== null) {
                $problem = 'That username is taken.';
            }
            if ($problem !== null) {
                $this->addFlash('error', $problem);
                return $this->redirectToRoute('app_register');
            }

            $user = new User();
            $user->setEmail($email);
            $user->setUsername($username);
            // Year-1 starting cash at today's price level, so a late joiner starts with the same purchasing power.
            $macro = $macroStates->liveState();
            $user->setCashBalance(\App\Service\Season\SeasonService::startingCapital($macro->consumerPriceLevel));

            $hashedPassword = $userPasswordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);

            try {
                $entityManager->persist($user);
                $entityManager->flush();
                $seasons->join($user, $macro->totalTime, (float) $user->getCashBalance());
                $entityManager->flush();

                // Generate a signed url and email it to the user
                $signatureComponents = $verifyEmailHelper->generateSignature(
                    'app_verify_email',
                    (string) $user->getId(),
                    (string) $user->getEmail(),
                    ['id' => $user->getId()],
                );

                $email = (new Email())
                    ->from('noreply@trade.lakebird.org')
                    ->to($user->getEmail())
                    ->subject('Please Confirm your Email')
                    ->html('<p>Click this link to verify your email:</p><a href="' . $signatureComponents->getSignedUrl() . '">Verify Email</a>');

                $mailer->send($email);

                $this->addFlash('success', 'Account created! Please check your email to verify your account.');
                return $this->redirectToRoute('app_login');
            } catch (UniqueConstraintViolationException $e) {
                // Someone registered the same email or username since the checks above.
                $this->addFlash('error', 'That email or username was just taken. Please choose another.');
                return $this->redirectToRoute('app_register');
            } catch (\Exception $e) {
                $logger->error('Registration failed.', ['exception' => $e]);
                $this->addFlash('error', 'Registration failed. Please try again.');
                return $this->redirectToRoute('app_register');
            }
        }

        return $this->render('registration/register.html.twig');
    }

    /**
     * What is wrong with a sign-up's email or username before it reaches the database, or null. The mailer refuses an
     * address it cannot send to, and the columns refuse a value longer than they hold.
     */
    public static function problemWith(string $email, string $username): ?string
    {
        try {
            new Address($email);
        } catch (AddressException) {
            return 'That email address is not valid.';
        }

        if (mb_strlen($email) > User::EMAIL_MAX_LENGTH) {
            return sprintf('Email addresses can be at most %d characters.', User::EMAIL_MAX_LENGTH);
        }

        if (mb_strlen($username) > User::USERNAME_MAX_LENGTH) {
            return sprintf('Usernames can be at most %d characters.', User::USERNAME_MAX_LENGTH);
        }

        return null;
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, UserRepository $userRepository, EntityManagerInterface $entityManager, VerifyEmailHelperInterface $verifyEmailHelper): Response
    {
        $id = $request->query->get('id'); // retrieve the user id from the url

        // Verify the user exists
        if (null === $id) {
            $this->addFlash('error', 'Invalid verification link. Missing user ID.');
            return $this->redirectToRoute('app_register');
        }

        $user = $userRepository->find($id);

        if (null === $user) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('app_register');
        }

        // Validate the signature using the modern FromRequest method
        try {
            $verifyEmailHelper->validateEmailConfirmationFromRequest($request, (string) $user->getId(), $user->getEmail());
        } catch (VerifyEmailExceptionInterface $e) {
            $this->addFlash('error', $e->getReason());
            return $this->redirectToRoute('app_register');
        }

        // Mark the user as verified
        $user->setIsVerified(true);
        $entityManager->flush();

        $this->addFlash('success', 'Your email address has been verified.');

        return $this->redirectToRoute('app_login');
    }
}
