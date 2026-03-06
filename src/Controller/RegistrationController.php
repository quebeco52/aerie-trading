<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request, 
        UserPasswordHasherInterface $userPasswordHasher, 
        EntityManagerInterface $entityManager
    ): Response {
        // 1. Best Practice: If they are already logged in, redirect them to the dashboard
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard'); 
        }

        // 2. Handle the Form Submission
        if ($request->isMethod('POST')) {
            // Validate the CSRF token
            $csrfToken = $request->request->get('_csrf_token');
            if (!$this->isCsrfTokenValid('register_form', $csrfToken)) {
                $this->addFlash('error', 'Invalid security token. Please try again.');
                return $this->redirectToRoute('app_register');
            }

            $email = $request->request->get('email');
            $plainPassword = $request->request->get('password');

            // Basic validation
            if (empty($email) || empty($plainPassword)) {
                $this->addFlash('error', 'Please provide an email and password.');
                return $this->redirectToRoute('app_register');
            }

            // 3. Create the User
            $user = new User();
            $user->setEmail($email);
            // Give them $10,000 in starting cash
            $user->setCashBalance('10000.00'); 

            // 4. Securely hash the password using Symfony's modern hasher (Argon2id)
            $hashedPassword = $userPasswordHasher->hashPassword(
                $user,
                $plainPassword
            );
            $user->setPassword($hashedPassword);

            // 5. Save to the database
            try {
                $entityManager->persist($user);
                $entityManager->flush();

                $this->addFlash('success', 'Your trading account has been created! Please log in.');
                return $this->redirectToRoute('app_login');
            } catch (\Exception $e) {
                // If the email already exists, Doctrine will throw a UniqueConstraintViolationException
                $this->addFlash('error', 'An account with that email already exists.');
                return $this->redirectToRoute('app_register');
            }
        }

        // Render the form for GET requests
        return $this->render('registration/register.html.twig');
    }
}