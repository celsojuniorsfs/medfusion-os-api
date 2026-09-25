<?php

namespace Tests\Architecture;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Guarda de fronteira do monólito modular (ver docs/architecture.md): Domain e Application de
 * um módulo nunca importam Domain/Application/Infrastructure/Presentation de outro módulo — a
 * única forma de um módulo "ver" outro é por uuid (passado como parâmetro) ou pelo nome de uma
 * classe de evento (Modules\{Outro}\Domain\Events\...), nunca pela classe do agregado ou
 * pelo read model.
 *
 * Infrastructure/ReadModels e Presentation ficam de fora deste teste de propósito: são o lado
 * de leitura, que compõe relações Eloquent entre módulos direto contra o banco compartilhado
 * (ex.: Order::client()) — uma composição de consulta, não uma decisão de domínio, e não motivo
 * para inventar uma camada de repositório só para escondê-la (ver a "instrução para não criar
 * abstrações" no topo de architecture.md).
 */
class ModuleBoundariesTest extends TestCase
{
    public function test_domain_and_application_layers_do_not_cross_module_boundaries(): void
    {
        $modulesPath = base_path('Modules');
        $violations = [];

        foreach (glob($modulesPath.'/*', GLOB_ONLYDIR) as $modulePath) {
            $module = basename($modulePath);

            foreach (['Domain', 'Application'] as $layer) {
                $layerPath = $modulePath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.$layer;

                if (! is_dir($layerPath)) {
                    continue;
                }

                $finder = (new Finder)->files()->in($layerPath)->name('*.php');

                foreach ($finder as $file) {
                    $contents = $file->getContents();

                    if (! preg_match_all('/^use\s+(Modules\\\\[^;]+);/m', $contents, $matches)) {
                        continue;
                    }

                    foreach ($matches[1] as $imported) {
                        $importedModule = explode('\\', $imported)[1] ?? null;

                        // Comparação sem diferenciar caixa — em filesystems case-insensitive
                        // (Windows/NTFS), o nome real do diretório do módulo às vezes destoa em
                        // caixa do namespace PSR-4 (ex.: `Modules/orders` no disco vs.
                        // `Modules\Orders\...` no `use`), mesmo com o Git rastreando o caminho
                        // certo. Isso fazia todo módulo se acusar de "importar a si mesmo" de
                        // outro módulo. Dois módulos DIFERENTES nunca colidem por caixa (nomes
                        // distintos), então isso não mascara violação de verdade.
                        if ($importedModule === null || strcasecmp($importedModule, $module) === 0) {
                            continue;
                        }

                        if (str_starts_with($imported, "Modules\\{$importedModule}\\Domain\\Events\\")) {
                            continue;
                        }

                        $violations[] = sprintf(
                            '%s importa %s (módulo %s → %s fora de Domain\Events)',
                            $file->getRelativePathname(),
                            $imported,
                            $module,
                            $importedModule,
                        );
                    }
                }
            }
        }

        $this->assertEmpty($violations, "Fronteira de módulo violada:\n".implode("\n", $violations));
    }
}
