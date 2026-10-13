<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Channels\Contracts\SupportsQuestions;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Questions\Actions\RequestQuestionAnswer;
use App\Domain\Questions\Models\ChannelQuestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * S4 · Müşteri soruları ekranı. Kiracı scope'u altında; başka kiracının ya
 * da olmayan sorunun cevabı AYNI 404'tür. Ekran kanala istek ATMAZ — durum
 * yerel kayıttan, yoklama günceller.
 */
final class QuestionController extends Controller
{
    public function index(Request $request, AdapterRegistry $registry): Response
    {
        $filter = $request->query('filter') === 'all' ? 'all' : 'unanswered';

        $query = ChannelQuestion::query()
            ->with('connection')
            ->orderByDesc('asked_at')
            ->orderByDesc('id');

        if ($filter === 'unanswered') {
            $query->where(fn ($q) => $q->unanswered()
                ->orWhereIn('answer_status', [ChannelQuestion::ANSWER_PENDING, ChannelQuestion::ANSWER_SUBMITTED]));
        }

        $page = $query->paginate(30)->withQueryString();

        $support = [];

        foreach ($page->getCollection()->pluck('connection')->filter()->unique('id') as $connection) {
            $support[$connection->id] = $this->supports($registry, $connection);
        }

        return Inertia::render('Questions/Index', [
            'filter' => $filter,
            'unansweredCount' => ChannelQuestion::query()->unanswered()->count(),
            'questions' => $page->through(fn (ChannelQuestion $q) => [
                'id' => $q->id,
                'channel' => $q->channel_type_code,
                'connection' => $q->connection?->label,
                'product' => $q->product_name,
                'productRef' => $q->product_ref,
                'body' => $q->body,
                'askedAt' => $q->asked_at?->toIso8601String(),
                'status' => $q->status,
                'statusLabel' => $q->statusEnum()->label(),
                'answerable' => $q->statusEnum()->answerable() && ! $q->answerInFlight(),
                'answerBody' => $q->answer_body,
                'answeredAt' => $q->answered_at?->toIso8601String(),
                'submittedAnswer' => $q->submitted_answer,
                'answerStatus' => $q->answer_status,
                'answerReason' => $q->answerReason()?->label(),
                'answerSupported' => $support[$q->channel_connection_id] ?? false,
                'answerEnabled' => $q->connection?->question_answer_enabled === true,
            ]),
        ]);
    }

    public function answer(string $question, RequestQuestionAnswer $answer, Request $request): RedirectResponse
    {
        // Kiracı scope'u altında: başka kiracının ya da olmayan soru AYNI 404.
        $question = ChannelQuestion::query()->with('connection')->findOrFail($question);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
        ]);

        $reason = $answer->run($question, $validated['text'], $request->user()?->id);

        return back()->with($reason === null ? 'success' : 'warning', $reason === null
            ? __('Cevap kanala gönderiliyor. Kanal yayınlayınca "Cevaplandı" görünür.')
            : $reason->label());
    }

    private function supports(AdapterRegistry $registry, ChannelConnection $connection): bool
    {
        try {
            return $registry->for($connection) instanceof SupportsQuestions;
        } catch (Throwable) {
            return false;
        }
    }
}
