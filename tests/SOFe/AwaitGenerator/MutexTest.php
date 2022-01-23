<?php

/*
 * await-generator
 *
 * Copyright (C) 2018 SOFe
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace SOFe\AwaitGenerator;

use Generator;
use PHPUnit\Framework\TestCase;

/**
 * @small
 */
class MutexTest extends TestCase{
	public function testInitAsIdle() : void{
		$mutex = new Mutex;
		self::assertTrue($mutex->isIdle(), "mutex should initialize is idle");
	}

	public function testNotIdleDuringLock() : void{
		$done = 4;

		Await::f2c(function() use(&$done) : Generator{
			$mutex = new Mutex;

			yield from $mutex->runClosure(function() use(&$done, $mutex) : Generator{
				false && yield;
				self::assertFalse($mutex->isIdle(), "mutex should not be idle when locked closure is running");
				$done--;
			});

			self::assertTrue($mutex->isIdle(), "mutex should be idle after unlock");
			$done--;

			yield from $mutex->runClosure(function() use(&$done, $mutex) : Generator{
				false && yield;
				self::assertFalse($mutex->isIdle(), "mutex should not be idle when locked closure is running again");
				$done--;
			});

			self::assertTrue($mutex->isIdle(), "mutex should be idle after unlocking again");
			$done--;
		});

		self::assertSame($done, 0, "all branches should be executed");
	}

	public function testMutualExclusion() : void{
		$eventCounter = 0;

		$clock = new MockClock;

		$mutex = new Mutex;

		Await::f2c(function() use(&$eventCounter, $mutex, $clock) : Generator{
			self::assertSame(0, $eventCounter++, "Await::f2c should start immediately");

			yield from $mutex->runClosure(function() use(&$eventCounter, $mutex, $clock) : Generator{
				self::assertSame(1, $eventCounter++, "mutex should start immediately");
				self::assertFalse($mutex->isIdle(), "mutex should not be idle when locked closure is running");

				yield from $clock->sleepUntil(2);

				self::assertSame(4, $eventCounter++, "mutex should run after clock ticks to 2");
				self::assertSame(2, $clock->currentTick(), "mock clock implementation error");
				self::assertFalse($mutex->isIdle(), "mutex should not be idle when locked closure is resumed");
			});
		});

		self::assertSame(2, $eventCounter++, "mock clock should preempt coroutine");

		Await::f2c(function() use(&$eventCounter, $mutex, $clock) : Generator{
			yield from $mutex->runClosure(function() use(&$eventCounter, $mutex, $clock) : Generator{
				self::assertSame(5, $eventCounter++, "mutex should start next task immediately");
				self::assertSame(2, $clock->currentTick(), "mutex should start next task immediately");
				self::assertFalse($mutex->isIdle(), "mutex should not be idle when lock is acquired concurrently");;

				yield from $clock->sleepUntil(4);

				self::assertSame(8, $eventCounter++, "mutex should run after clock ticks to 4");
				self::assertSame(4, $clock->currentTick(), "mock clock implementation error");
				self::assertFalse($mutex->isIdle(), "mutex should not be idle when locked closure is resumed again");
			});
		});

		$clock->nextTick(1);

		self::assertSame(3, $eventCounter++, "mock clock implementation error");
		self::assertFalse($mutex->isIdle(), "mutex should not be idle when locked closure is preempted");

		$clock->nextTick(2);

		self::assertSame(6, $eventCounter++, "nextTick should resume coroutine");
		self::assertFalse($mutex->isIdle(), "mutex should not be idle when lock is acquired concurrently");;

		$clock->nextTick(3);

		self::assertSame(7, $eventCounter++, "mock clock implementation error");
		self::assertFalse($mutex->isIdle(), "mock clock implementation error");

		$clock->nextTick(4);

		self::assertSame(9, $eventCounter++, "nextTick should resume coroutine");
		self::assertTrue($mutex->isIdle(), "mutex should be idle when both locks are released");;
	}
}
