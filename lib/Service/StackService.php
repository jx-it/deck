<?php

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Deck\Service;

use OCA\Deck\Activity\ActivityManager;
use OCA\Deck\Activity\ChangeSet;
use OCA\Deck\BadRequestException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\ChangeHelper;
use OCA\Deck\Db\LabelMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Event\BoardUpdatedEvent;
use OCA\Deck\Model\CardDetails;
use OCA\Deck\NoPermissionException;
use OCA\Deck\StatusException;
use OCA\Deck\Validators\StackServiceValidator;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

class StackService
{
	public function __construct(
		private readonly StackMapper $stackMapper,
		private readonly BoardMapper $boardMapper,
		private readonly CardMapper $cardMapper,
		private readonly LabelMapper $labelMapper,
		private readonly PermissionService $permissionService,
		private readonly BoardService $boardService,
		private readonly CardService $cardService,
		private readonly AssignmentMapper $assignedUsersMapper,
		private readonly AttachmentService $attachmentService,
		private readonly ActivityManager $activityManager,
		private readonly ChangeHelper $changeHelper,
		private readonly LoggerInterface $logger,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly StackServiceValidator $stackServiceValidator,
	) {
	}

	/** @param Stack[] $stacks */
	private function enrichStacksWithCards(array $stacks, int $since = -1): void
	{
		$cardsByStackId = $this->cardMapper->findAllForStacks(array_map(fn(Stack $stack) => $stack->getId(), $stacks), null, 0, $since);

		foreach ($cardsByStackId as $stackId => $cards) {
			if (!$cards) {
				continue;
			}

			foreach ($stacks as $stack) {
				if ($stack->getId() === $stackId) {
					$stack->setCards($this->cardService->enrichCards($cards));
					break;
				}
			}
		}
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws BadRequestException
	 */
	public function find(int $stackId): Stack
	{
		$this->permissionService->checkPermission($this->stackMapper, $stackId, Acl::PERMISSION_READ);
		$stack = $this->stackMapper->find($stackId);

		$allCards = $this->cardMapper->findAll($stackId);
		$cardIds = array_map(fn(Card $card) => $card->getId(), $allCards);
		$attachmentCounts = $this->attachmentService->countForCards($cardIds);
		$assignedUsers = $this->assignedUsersMapper->findIn($cardIds);

		$cards = array_map(
			function (Card $card) use ($attachmentCounts, $assignedUsers): CardDetails {
				$cardAssignedUsers = array_values(array_filter($assignedUsers, fn($a) => $a->getCardId() === $card->getId()));
				$card->setAssignedUsers($cardAssignedUsers);
				$card->setAttachmentCount($attachmentCounts[$card->getId()] ?? 0);

				return new CardDetails($card);
			},
			$allCards
		);

		$stack->setCards($cards);

		return $stack;
	}

	/**
	 * @return Stack[]
	 * @throws \OCA\Deck\NoPermissionException
	 * @throws BadRequestException
	 */
	public function findAll(int $boardId, int $since = -1): array
	{
		$this->permissionService->checkPermission(null, $boardId, Acl::PERMISSION_READ);
		$stacks = $this->stackMapper->findAll($boardId);
		$this->enrichStacksWithCards($stacks, $since);

		return $stacks;
	}

	/**
	 * @return Stack[]
	 * @throws \OCP\DB\Exception
	 */
	public function findCalendarEntries(int $boardId): array
	{
		try {
			$this->permissionService->checkPermission(null, $boardId, Acl::PERMISSION_READ);
		} catch (NoPermissionException $e) {
			$this->logger->error('Unable to check permission for a previously obtained board ' . $boardId, ['exception' => $e]);
			return [];
		}
		return $this->stackMapper->findAll($boardId);
	}

	/**
	 * @return Stack[]
	 * @throws \OCP\DB\Exception
	 */
	public function fetchDeleted(int $boardId): array
	{
		$this->permissionService->checkPermission($this->boardMapper, $boardId, Acl::PERMISSION_READ);
		$stacks = $this->stackMapper->findDeleted($boardId);
		$this->enrichStacksWithCards($stacks);

		return $stacks;
	}

	/**
	 * @return Stack[]
	 * @throws \OCA\Deck\NoPermissionException
	 * @throws BadRequestException
	 */
	public function findAllArchived(int $boardId): array
	{
		$this->permissionService->checkPermission(null, $boardId, Acl::PERMISSION_READ);
		$stacks = $this->stackMapper->findAll($boardId);
		$labels = $this->labelMapper->getAssignedLabelsForBoard($boardId);

		$stackIds = array_map(fn(Stack $stack) => $stack->getId(), $stacks);

		// Fetch all archived cards for all stacks in a single query
		$cardsByStackId = $this->cardMapper->findAllArchivedForStacks($stackIds);

		$allArchivedCardIds = [];
		foreach ($cardsByStackId as $cards) {
			foreach ($cards as $card) {
				$allArchivedCardIds[] = $card->getId();
			}
		}

		$attachmentCounts = $this->attachmentService->countForCards($allArchivedCardIds);

		foreach ($stacks as $stackIndex => $stack) {
			$cards = $cardsByStackId[$stack->getId()] ?? [];
			foreach ($cards as $cardIndex => $card) {
				if (array_key_exists($card->id, $labels)) {
					$cards[$cardIndex]->setLabels($labels[$card->id]);
				}
				$cards[$cardIndex]->setAttachmentCount($attachmentCounts[$card->getId()] ?? 0);
			}
			$stacks[$stackIndex]->setCards($cards);
		}

		/** @var Stack[] $stacks */
		return $stacks;
	}

	/**
	 * @throws StatusException
	 * @throws \OCA\Deck\NoPermissionException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws BadRequestException
	 */
	public function create(string $title, int $boardId, int $order): Stack
	{
		$this->stackServiceValidator->check(compact('title', 'boardId', 'order'));

		$this->permissionService->checkPermission(null, $boardId, Acl::PERMISSION_MANAGE);
		if ($this->boardService->isArchived(null, $boardId)) {
			throw new StatusException('Operation not allowed. This board is archived.');
		}
		$stack = new Stack();
		$stack->setTitle($title);
		$stack->setBoardId($boardId);
		$stack->setOrder($order);
		$stack = $this->stackMapper->insert($stack);
		$this->activityManager->triggerEvent(
			ActivityManager::DECK_OBJECT_BOARD,
			$stack,
			ActivityManager::SUBJECT_STACK_CREATE,
			[],
			$this->permissionService->getUserId()
		);
		$this->changeHelper->boardChanged($boardId);
		$this->eventDispatcher->dispatchTyped(new BoardUpdatedEvent($boardId));

		return $stack;
	}

	/**
	 * @return Stack The deleted stack.
	 * @throws \OCA\Deck\NoPermissionException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws BadRequestException
	 */
	public function delete(int $id): Stack
	{
		$this->permissionService->checkPermission($this->stackMapper, $id, Acl::PERMISSION_MANAGE);

		$stack = $this->stackMapper->find($id);
		$stack->setDeletedAt(time());
		$stack = $this->stackMapper->update($stack);

		$this->activityManager->triggerEvent(
			ActivityManager::DECK_OBJECT_BOARD,
			$stack,
			ActivityManager::SUBJECT_STACK_DELETE,
			[],
			$this->permissionService->getUserId()
		);
		$this->changeHelper->boardChanged($stack->getBoardId());
		$this->eventDispatcher->dispatchTyped(new BoardUpdatedEvent($stack->getBoardId()));
		$this->enrichStacksWithCards([$stack]);

		return $stack;
	}

	/**
	 * @throws StatusException
	 * @throws \OCA\Deck\NoPermissionException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws BadRequestException
	 */
	public function update(int $id, string $title, int $boardId, int $order, ?int $deletedAt): Stack
	{
		$this->stackServiceValidator->check(compact('id', 'title', 'boardId', 'order'));

		$this->permissionService->checkPermission($this->stackMapper, $id, Acl::PERMISSION_MANAGE);
		$this->permissionService->checkPermission($this->boardMapper, $boardId, Acl::PERMISSION_MANAGE);

		if ($this->boardService->isArchived($this->stackMapper, $id)) {
			throw new StatusException('Operation not allowed. This board is archived.');
		}

		$stack = $this->stackMapper->find($id);
		$changes = new ChangeSet($stack);
		$stack->setTitle($title);
		$stack->setBoardId($boardId);
		$stack->setOrder($order);
		$stack->setDeletedAt($deletedAt);
		$changes->setAfter($stack);
		$stack = $this->stackMapper->update($stack);
		$this->activityManager->triggerUpdateEvents(
			ActivityManager::DECK_OBJECT_BOARD,
			$changes,
			ActivityManager::SUBJECT_STACK_UPDATE
		);
		$this->changeHelper->boardChanged($stack->getBoardId());
		$this->eventDispatcher->dispatchTyped(new BoardUpdatedEvent($stack->getBoardId()));

		return $stack;
	}

	/**
	 * @return array<int, Stack> The stacks in the correct order.
	 * @throws \OCA\Deck\NoPermissionException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws BadRequestException
	 */
	public function reorder(int $id, int $order): array
	{
		$this->stackServiceValidator->check(compact('id', 'order'));

		$this->permissionService->checkPermission($this->stackMapper, $id, Acl::PERMISSION_MANAGE);
		$stackToSort = $this->stackMapper->find($id);
		$stacks = $this->stackMapper->findAll($stackToSort->getBoardId());
		usort($stacks, static fn(Stack $stackA, Stack $stackB) => $stackA->getOrder() - $stackB->getOrder());
		$result = [];
		$i = 0;
		foreach ($stacks as $stack) {
			if ($stack->id === $id) {
				$stack->setOrder($order);
			}

			if ($i === $order) {
				$i++;
			}

			if ($stack->id !== $id) {
				$stack->setOrder($i++);
			}
			$stack = $this->stackMapper->update($stack);
			$result[$stack->getOrder()] = $stack;
		}
		$this->changeHelper->boardChanged($stackToSort->getBoardId());
		$this->eventDispatcher->dispatchTyped(new BoardUpdatedEvent($stackToSort->getBoardId()));

		return $result;
	}

	/**
	 * Set or unset a stack as the "done column" for the board
	 *
	 * @throws StatusException
	 * @throws \OCA\Deck\NoPermissionException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws BadRequestException
	 */
	public function setDoneStack(int $stackId, int $boardId, bool $isDone): void
	{
		$this->permissionService->checkPermission($this->stackMapper, $stackId, Acl::PERMISSION_MANAGE);

		if ($this->boardService->isArchived($this->stackMapper, $stackId)) {
			throw new NoPermissionException('Operation not allowed. This board is archived.');
		}

		if ($isDone) {
			$this->stackMapper->clearDoneColumnForBoard($boardId);
			// Mark all existing cards in the stack as done
			/** @var Card $card */
			foreach ($this->cardMapper->findAll($stackId) as $card) {
				if ($card->getDone() === null) {
					$card->setDone(new \DateTime());
					$this->cardMapper->update($card);
				}
			}
		}

		$this->stackMapper->setIsDoneColumn($stackId, $isDone);
		$this->changeHelper->boardChanged($boardId);
		$this->eventDispatcher->dispatchTyped(new BoardUpdatedEvent($boardId));
	}

	/**
	 * Clone a stack as a template with date and text modifications
	 *
	 * @param int $stackId The stack ID to clone
	 * @param int|null $targetBoardId The target board ID (null = same board as source)
	 * @param array $dateShift Array with 'months' and 'days' keys for date shifting
	 * @param array $textReplacements Array of ['search' => '', 'replace' => ''] arrays
	 * @return Stack The newly created stack
	 * @throws NoPermissionException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 * @throws BadRequestException
	 * @throws StatusException
	 */
	public function cloneAsTemplate(int $stackId, ?int $targetBoardId, array $dateShift, array $textReplacements): Stack
	{
		// Don't validate stackId in array since it's a direct parameter
		// $this->stackServiceValidator->check(compact('stackId'));

		// Check read permission for the source stack
		$this->permissionService->checkPermission($this->stackMapper, $stackId, Acl::PERMISSION_READ);

		// Get the source stack
		$sourceStack = $this->stackMapper->find($stackId);
		$sourceBoardId = $sourceStack->getBoardId();

		// Determine target board (default to source board if not specified)
		$boardId = $targetBoardId ?? $sourceBoardId;

		// Check manage permission for the target board
		$this->permissionService->checkPermission(null, $boardId, Acl::PERMISSION_MANAGE);

		if ($this->boardService->isArchived(null, $boardId)) {
			throw new StatusException('Operation not allowed. This board is archived.');
		}

		// Create the new stack
		$newStack = new Stack();
		$newStack->setTitle($sourceStack->getTitle() . ' (Copy)');
		$newStack->setBoardId($boardId);

		// Set order as the last stack in the board
		$allStacks = $this->stackMapper->findAll($boardId);
		$maxOrder = 0;
		foreach ($allStacks as $stack) {
			if ($stack->getOrder() > $maxOrder) {
				$maxOrder = $stack->getOrder();
			}
		}
		$newStack->setOrder($maxOrder + 1);

		// Insert the new stack
		$newStack = $this->stackMapper->insert($newStack);

		// Clone all cards from the source stack
		$sourceCards = $this->cardMapper->findAll($stackId);

		foreach ($sourceCards as $sourceCard) {
			$newCard = new Card();

			// Apply text replacements to title and description
			$title = $sourceCard->getTitle();
			$description = $sourceCard->getDescription();

			foreach ($textReplacements as $replacement) {
				if (!empty($replacement['search']) && isset($replacement['replace'])) {
					$title = str_replace($replacement['search'], $replacement['replace'], $title);
					$description = str_replace($replacement['search'], $replacement['replace'], $description);
				}
			}

			$newCard->setTitle($title);
			$newCard->setDescription($description);
			$newCard->setStackId($newStack->getId());
			$newCard->setType($sourceCard->getType());
			$newCard->setOwner($sourceCard->getOwner());
			$newCard->setOrder($sourceCard->getOrder());

			// Apply date shift to due date
			$dueDate = $sourceCard->getDuedate();
			if ($dueDate !== null) {
				try {
					// getDuedate() already returns a DateTime object, not a string
					if ($dueDate instanceof \DateTime) {
						$date = clone $dueDate; // Clone to avoid modifying original
					} else {
						$date = new \DateTime($dueDate);
					}

					// Add months
					if (!empty($dateShift['months'])) {
						$months = (int) $dateShift['months'];
						$date->modify("{$months} months");
					}

					// Add days
					if (!empty($dateShift['days'])) {
						$days = (int) $dateShift['days'];
						$date->modify("{$days} days");
					}

					$newCard->setDuedate($date->format('Y-m-d H:i:s'));
				} catch (\Exception $e) {
					// If date parsing fails, don't set a due date
					$newCard->setDuedate(null);
				}
			}

			// Don't copy archived status or done status
			$newCard->setArchived(false);
			$newCard->setDone(null);

			// Insert the new card
			$newCard = $this->cardMapper->insert($newCard);

			// Copy labels
			$labels = $this->labelMapper->findAssignedLabelsForCard($sourceCard->getId());
			foreach ($labels as $label) {
				$this->cardMapper->assignLabel($newCard->getId(), $label->getId());
			}

			// Copy assignments
			$assignments = $this->assignedUsersMapper->findAll($sourceCard->getId());
			foreach ($assignments as $assignment) {
				$this->assignmentService->assignUser($newCard->getId(), $assignment->getParticipant(), $assignment->getType());
			}
		}

		// Trigger events
		$this->activityManager->triggerEvent(
			ActivityManager::DECK_OBJECT_BOARD,
			$newStack,
			ActivityManager::SUBJECT_STACK_CREATE
		);
		$this->changeHelper->boardChanged($boardId);
		$this->eventDispatcher->dispatchTyped(new BoardUpdatedEvent($boardId));

		// Return the new stack with cards
		return $this->find($newStack->getId());
	}
}
