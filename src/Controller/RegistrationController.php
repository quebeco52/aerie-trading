<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request, 
        UserPasswordHasherInterface $userPasswordHasher, 
        EntityManagerInterface $entityManager,
        VerifyEmailHelperInterface $verifyEmailHelper,
        MailerInterface $mailer
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard'); 
        }

        if ($request->isMethod('POST')) {
            $csrfToken = $request->request->get('_csrf_token');
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

            $user = new User();
            $user->setEmail($email);
            $user->setUsername($username);
            $user->setCashBalance('10000.00'); 

            $hashedPassword = $userPasswordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);

            try {
                $entityManager->persist($user);
                $entityManager->flush();

                // Generate a signed url and email it to the user
                $signatureComponents = $verifyEmailHelper->generateSignature(
                    'app_verify_email',
                    (string) $user->getId(),
                    (string) $user->getEmail(),
                    ['id' => $user->getId()]
                );

                $email = (new Email())
                    ->from('noreply@trade.lakebird.org')
                    ->to($user->getEmail())
                    ->subject('Please Confirm your Email')
                    ->html('<p>Click this link to verify your email:</p><a href="'.$signatureComponents->getSignedUrl().'">Verify Email</a>');

                $mailer->send($email);

                $this->addFlash('success', 'Account created! Please check your email to verify your account.');
                return $this->redirectToRoute('app_login');
            } catch (UniqueConstraintViolationException $e) {
                $this->addFlash('error', 'An account with that email already exists.');
                return $this->redirectToRoute('app_register');
            } catch (\Exception $e) {
                $this->addFlash('error', 'An error occurred during registration: ' . $e->getMessage());
                return $this->redirectToRoute('app_register');
            }
        }

        return $this->render('registration/register.html.twig');
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