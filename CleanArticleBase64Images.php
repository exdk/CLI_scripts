<?php

namespace App\Console\Commands;

use App\Models\Wiki\Article;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanArticleBase64Images extends Command
{
    protected $signature = 'article:clean-base64';
    protected $description = 'Находит встроенные base64-картинки в статьях и выносит их в /storage/uploads/articles, заменяя их на ссылки';

    /**
     * Handle the execution of the command.
     *
     * Находит встроенные base64-картинки в статьях, сохраняет их в файловую систему
     * и заменяет в тексте статьи на публичные ссылки.
     *
     * @return void
     */
    public function handle()
    {
        ini_set('memory_limit', '2048M');
        $this->info('Ищем статьи с base64-картинками...');

        $articles = Article::where('text', 'like', '%data:image/%')->get();
        $totalBefore = 0;
        $totalAfter = 0;

        $bar = $this->output->createProgressBar(count($articles));
        $bar->start();

        foreach ($articles as $article) {
            try {
                $bar->advance();
                $html = $article->text;
                $beforeSize = strlen($html);
                $count = 0;

                if (preg_match_all('/data:image\/(png|jpeg|jpg|gif);base64,([^"\']+)/i', $html, $matches)) {
                    foreach ($matches[2] as $i => $data) {
                        $image = base64_decode($data);
                        if (!$image) continue;

                        if (strlen($image) > 20 * 1024) {
                            $ext = $matches[1][$i];
                            $filename = uniqid('article_') . '.' . $ext;
                            $path = "uploads/articles/{$filename}";
                            $fullPath = public_path($path);

                            if (!file_exists(dirname($fullPath))) {
                                mkdir(dirname($fullPath), 0755, true);
                            }

                            file_put_contents($fullPath, $image);
                            $publicPath = '/' . $path;

                            $html = str_replace($matches[0][$i], $publicPath, $html);
                            $count++;
                        }
                    }
                }

                if ($count > 0) {
                    $article->text = $html;
                    $article->save();

                    $afterSize = strlen($html);
                    $totalBefore += $beforeSize;
                    $totalAfter += $afterSize;

                    $this->newLine();
                    $this->info("Статья #{$article->id}: вынесено {$count} изображений");
                    $this->line("Было: " . round($beforeSize / 1024 / 1024, 2) . " МБ → Стало: " . round($afterSize / 1024 / 1024, 2) . " МБ");
                }
            } catch (\Throwable $e) {
                $this->error("Ошибка в статье #{$article->id}: {$e->getMessage()}");
                Log::error("Ошибка при обработке статьи #{$article->id}", [
                    'exception' => $e,
                ]);
                continue;
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Завершено!');
        $this->line('Суммарно до:  ' . round($totalBefore / 1024 / 1024, 2) . ' МБ');
        $this->line('Суммарно после: ' . round($totalAfter / 1024 / 1024, 2) . ' МБ');
        $this->line('Сэкономлено: ' . round(($totalBefore - $totalAfter) / 1024 / 1024, 2) . ' МБ');
    }
}
