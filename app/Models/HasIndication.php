<?php

namespace App\Models;

/**
 * The clinical indication a request line claims, as it should read.
 *
 * Shared by a requirement line and by each allocation line cut from it, which
 * carry the same certified indication.
 */
trait HasIndication
{
    /**
     * Get the indication as it should read on paper and on screen.
     *
     * An "Others" code carries no criterion of its own — the requester's own
     * words are the indication — so those are shown instead of the placeholder
     * text the enum holds for them.
     */
    public function indicationText(): ?string
    {
        if ($this->indication_code === null) {
            return null;
        }

        return $this->indication_code->triggersReview() && $this->indication_other !== null
            ? $this->indication_other
            : $this->indication_code->description();
    }
}
