<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class IdeaController extends AbstractController
{
    #[Route('/', name: 'homepage')]
    public function index(): Response
    {
        return new Response(<<<EOF
        <html>
            <body>
                <h1>ça va</h1>
            </body>
        </html>
        EOF
        );    

        // return $this->render('index.html.twig');
    }
}
