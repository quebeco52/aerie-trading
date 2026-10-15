<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\District\Institutions;
use App\Data\Politics\AerieCouncil;
use App\Service\Lore\Almanac;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The District Almanac: its index by kind of entry, and each entry. */
class AlmanacController extends AbstractController
{
    #[Route('/almanac', name: 'app_almanac', methods: ['GET'])]
    public function index(Almanac $almanac): Response
    {
        $sections = array_fill_keys(Almanac::CATEGORIES, []);
        foreach ($almanac->all() as $entry) {
            $sections[$entry->category][] = $entry;
        }

        return $this->render('almanac/index.html.twig', ['sections' => array_filter($sections)]);
    }

    #[Route('/almanac/{slug}', name: 'app_almanac_entry', methods: ['GET'], requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])]
    public function entry(Almanac $almanac, string $slug): Response
    {
        $entry = $almanac->get($slug) ?? throw $this->createNotFoundException();
        $all = $almanac->all();
        $hrefs = array_filter(array_column(AerieCouncil::DEPARTMENTS, 'href', 'name'));

        return $this->render('almanac/entry.html.twig', [
            'entry' => $entry,
            // Entries that link here, so a reader can follow a thread back.
            'linkedFrom' => array_values(array_filter($all, static fn($other): bool => in_array($slug, $other->links, true))),
            // Institutions with a page of their own the entry is about.
            'pages' => array_values(array_filter(array_map(
                static fn(string $subject): ?array => isset($hrefs[Institutions::PUBLISHERS[$subject] ?? '']) ? ['name' => Institutions::PUBLISHERS[$subject], 'href' => $hrefs[Institutions::PUBLISHERS[$subject]]] : null,
                $entry->about
            ))),
        ]);
    }
}
