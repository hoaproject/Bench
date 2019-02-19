<?php

declare(strict_types=1);

/**
 * Hoa
 *
 *
 * @license
 *
 * New BSD License
 *
 * Copyright © 2007-2017, Hoa community. All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *     * Neither the name of the Hoa nor the names of its contributors may be
 *       used to endorse or promote products derived from this software without
 *       specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDERS AND CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace Hoa\Bench;

use Hoa\Consistency;
use Hoa\Iterator;

/**
 * Class \Hoa\Bench.
 *
 * The Hoa\Bench class allows to manage marks easily, and to make some
 * statistics.
 * The Hoa\Bench class implements Iterator and Countable interfaces to iterate
 * marks, or count the number of marks.
 */
class Bench implements Iterator\Aggregate, \Countable
{
    /**
     * Statistic : get the result.
     *
     * @const int
     */
    const STAT_RESULT   = 0;

    /**
     * Statistic : get the pourcent.
     *
     * @const int
     */
    const STAT_POURCENT = 1;

    /**
     * Collection of marks.
     *
     * @var array
     */
    protected static $_mark = [];

    /**
     * Collection of filters.
     *
     * @var array
     */
    protected $_filters     = [];



    /**
     * Get a mark.
     * If the mark does not exist, it will be automatically create.
     */
    public function __get(string $id): Mark
    {
        if (true === empty(self::$_mark)) {
            $global                         = new Mark(Mark::GLOBAL_NAME);
            self::$_mark[Mark::GLOBAL_NAME] = $global;
            $global->start();
        }

        if (true === $this->markExists($id)) {
            return self::$_mark[$id];
        }

        $mark             = new Mark($id);
        self::$_mark[$id] = $mark;

        return $mark;
    }

    /**
     * Check if a mark exists.
     * Alias of the protected markExist method.
     */
    public function __isset(string $id): bool
    {
        return $this->markExists($id);
    }

    /**
     * Destroy a mark.
     */
    public function __unset(string $id)
    {
        unset(self::$_mark[$id]);

        return;
    }

    /**
     * Destroy all mark.
     */
    public function unsetAll()
    {
        self::$_mark = [];

        return;
    }

    /**
     * Check if a mark already exists.
     */
    protected function markExists(string $id): bool
    {
        return isset(self::$_mark[$id]);
    }

    /**
     * Iterate over marks.
     */
    public function getIterator(): \Traversable
    {
        foreach (self::$_mark as $mark) {
            yield $mark;
        }
    }

    /**
     * Pause all marks and return only previously started tasks.
     */
    public function pause(): array
    {
        $startedMarks = [];

        foreach ($this as $mark) {
            if (true === $mark->isStarted() && false === $mark->isPause()) {
                $startedMarks[] = $mark;
            }
        }

        foreach ($startedMarks as $mark) {
            $mark->pause();
        }

        return $startedMarks;
    }

    /**
     * Resume a specific set of marks.
     */
    public static function resume(array $marks)
    {
        foreach ($marks as $mark) {
            $mark->start();
        }

        return;
    }

    /**
     * Add a filter.
     * Used in the self::getStatistic() method, no in iterator.
     * A filter is a callable that will receive 3 values about a mark: ID, time
     * result, and time pourcent. The callable must return a boolean.
     */
    public function filter($callable): self
    {
        $this->_filters[] = xcallable($callable);

        return $this;
    }

    /**
     * Return all filters.
     */
    public function getFilters(): array
    {
        return $this->_filters;
    }

    /**
     * Get statistic.
     * Return an associative array : id => sub-array. The sub-array contains the
     * result time in second (given by the constant self::STAT_RESULT), and the
     * result pourcent (given by the constant self::START_POURCENT).
     */
    public function getStatistic(bool $considerFilters = true): array
    {
        if (empty(self::$_mark)) {
            return [];
        }

        $startedMarks = $this->pause();

        $max = $this->getLongest()->diff();
        $out = [];

        foreach ($this as $id => $mark) {
            $result   = $mark->diff();
            $pourcent = ($result * 100) / $max;

            if (true === $considerFilters) {
                foreach ($this->getFilters() as $filter) {
                    if (true !== $filter($id, $result, $pourcent)) {
                        continue 2;
                    }
                }
            }

            $out[$id] = [
                self::STAT_RESULT   => $result,
                self::STAT_POURCENT => $pourcent
            ];
        }

        static::resume($startedMarks);

        return $out;
    }

    /**
     * Get the maximum, i.e. the longest mark in time.
     */
    public function getLongest(): Mark
    {
        $max     = 0;
        $outMark = null;

        foreach ($this as $id => $mark) {
            if ($mark->diff() > $max) {
                $outMark = $mark;
                $max     = $mark->diff();
            }
        }

        return $outMark;
    }

    /**
     * Draw statistic in text mode.
     */
    public function drawStatistic(int $width = 80): string
    {
        if (empty(self::$_mark)) {
            return '';
        }

        if ($width < 1) {
            throw new Exception(
                'The graphic width must be positive, given %d.',
                0,
                $width
            );
        }

        $out    = null;
        $stats  = $this->getStatistic();
        $margin = 0;

        foreach ($stats as $id => $foo) {
            strlen($id) > $margin and $margin = strlen($id);
        }

        $width   = $width - $margin - 18;
        $format  = '%-' . $margin . 's  %-' . $width . 's %5dms, %5.1f%%' . "\n";

        foreach ($stats as $id => $stat) {
            $out .= sprintf(
                $format,
                $id,
                str_repeat(
                    '|',
                    round(($stat[self::STAT_POURCENT] * $width) / 100)
                ),
                round(1000 * $stat[self::STAT_RESULT]),
                round($stat[self::STAT_POURCENT], 3)
            );
        }

        return $out;
    }

    /**
     * Count the number of mark.
     */
    public function count(): int
    {
        return count(self::$_mark);
    }

    /**
     * Alias of drawStatistic() method.
     */
    public function __toString(): string
    {
        return $this->drawStatistic();
    }
}

/**
 * Flex entity.
 */
Consistency::flexEntity(Bench::class);
