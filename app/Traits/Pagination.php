<?php

namespace App\Traits;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

trait Pagination
{
    protected function paginationData(
        LengthAwarePaginator|CursorPaginator $paginated
    ): array {
        return match (true) {
            $paginated instanceof CursorPaginator =>
                $this->cursorPaginationData($paginated),

            $paginated instanceof LengthAwarePaginator =>
                $this->pagePaginationData($paginated),
        };
    }

    private function pagePaginationData(LengthAwarePaginator $paginated): array
    {
        return [
            'current_page' => $paginated->currentPage(),
            'total_pages' => $paginated->lastPage(),
            'count' => $paginated->count(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
        ];
    }

    private function cursorPaginationData(CursorPaginator $paginated): array
    {
        return [
            'prev_cursor' => $paginated->previousCursor()?->encode(),
            'next_cursor' => $paginated->nextCursor()?->encode(),
            'prev_page_url' => $paginated->previousPageUrl(),
            'next_page_url' => $paginated->nextPageUrl(),
        ];
    }
}
