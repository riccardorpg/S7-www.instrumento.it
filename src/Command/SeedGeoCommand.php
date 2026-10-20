<?php

namespace App\Command;

use App\Entity\City;
use App\Entity\Province;
use App\Entity\Region;
use App\Entity\Zip;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Popola le tabelle geo del Master con un dataset CAMPIONE (regioni/province/comuni/CAP),
 * così il picker città/CAP è subito funzionante. Idempotente (upsert per nome/codice).
 *
 * Per il dataset ISTAT completo dei comuni + CAP, sostituire/estendere questo seed o
 * importare un dump SQL nelle tabelle eb_m_region/province/city/zip/join_table_city_zip.
 */
#[AsCommand(name: 'app:seed-geo', description: 'Popola il Master con un dataset campione di regioni/province/comuni/CAP')]
class SeedGeoCommand extends Command
{
    /** REGIONE => [ [sigla, provincia, [ [comune, [CAP, ...]], ... ] ], ... ] */
    private const DATA = [
        'Lombardia' => [
            ['MI', 'Milano', [['Milano', ['20121', '20122', '20123', '20124']], ['Sesto San Giovanni', ['20099']], ['Rho', ['20017']]]],
            ['MB', 'Monza e della Brianza', [['Monza', ['20900']], ['Lissone', ['20851']]]],
            ['BG', 'Bergamo', [['Bergamo', ['24121', '24122']]]],
        ],
        'Lazio' => [
            ['RM', 'Roma', [['Roma', ['00118', '00184', '00187', '00193']], ['Fiumicino', ['00054']]]],
            ['LT', 'Latina', [['Latina', ['04100']]]],
        ],
        'Piemonte' => [
            ['TO', 'Torino', [['Torino', ['10121', '10122', '10123']], ['Moncalieri', ['10024']]]],
        ],
        'Campania' => [
            ['NA', 'Napoli', [['Napoli', ['80121', '80122', '80133']], ['Pozzuoli', ['80078']]]],
        ],
        'Veneto' => [
            ['VE', 'Venezia', [['Venezia', ['30121', '30122']], ['Mestre', ['30171']]]],
            ['PD', 'Padova', [['Padova', ['35121', '35122']]]],
        ],
        'Emilia-Romagna' => [
            ['BO', 'Bologna', [['Bologna', ['40121', '40122', '40126']]]],
            ['MO', 'Modena', [['Modena', ['41121']]]],
        ],
        'Toscana' => [
            ['FI', 'Firenze', [['Firenze', ['50121', '50122', '50123']]]],
        ],
    ];

    public function __construct(private readonly ManagerRegistry $registry)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $em = $this->registry->getManager();
        $regionRepo = $em->getRepository(Region::class);
        $provinceRepo = $em->getRepository(Province::class);
        $cityRepo = $em->getRepository(City::class);
        $zipRepo = $em->getRepository(Zip::class);

        $nCities = 0;
        $nZips = 0;

        foreach (self::DATA as $regionName => $provinces) {
            $region = $regionRepo->findOneBy(['name' => $regionName]) ?? (new Region())->setName($regionName);
            $em->persist($region);

            foreach ($provinces as [$sign, $provinceName, $cities]) {
                $province = $provinceRepo->findOneBy(['sign' => $sign])
                    ?? (new Province())->setName($provinceName)->setSign($sign)->setRegion($region);
                $province->setRegion($region);
                $em->persist($province);

                foreach ($cities as [$cityName, $zipCodes]) {
                    $city = $cityRepo->findOneBy(['name' => $cityName, 'province' => $province])
                        ?? (new City())->setName($cityName)->setProvince($province)->setRegion($region);
                    $city->setProvince($province)->setRegion($region);
                    $em->persist($city);
                    ++$nCities;

                    foreach ($zipCodes as $code) {
                        $zip = $zipRepo->findOneBy(['code' => $code]) ?? (new Zip())->setCode($code);
                        $em->persist($zip);
                        $city->addZip($zip);
                        ++$nZips;
                    }
                }
            }
        }

        $em->flush();
        $io->success(sprintf('Dataset geo campione caricato: %d comuni, %d CAP.', $nCities, $nZips));

        return Command::SUCCESS;
    }
}
