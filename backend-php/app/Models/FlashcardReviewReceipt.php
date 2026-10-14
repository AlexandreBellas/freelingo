<?php

namespace App\Models;

class FlashcardReviewReceipt extends BaseModel
{
    protected $table = 'flashcard_review_receipts';

    public $incrementing = false;

    protected $primaryKey = 'flashcard_id';

    public function setKeysForSaveQuery($query)
    {
        return $query->where('flashcard_id', $this->getAttribute('flashcard_id'))
            ->where('review_id', $this->getAttribute('review_id'));
    }
    protected $fillable = [
        'flashcard_id',
        'review_id',
        'quality',
        'response',
    ];

    protected $casts = [
        'response' => 'array',
    ];
}
