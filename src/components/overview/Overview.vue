<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="overview-wrapper">
		<Controls :overview-name="filterDisplayName" />

		<div v-if="loading" key="loading" class="emptycontent">
			<div class="icon icon-loading" />
			<h2>{{ t('deck', 'Loading filtered view') }}</h2>
			<p />
		</div>

		<div v-else-if="isValidFilter" class="overview">
			<div v-for="columnProps in columnPropsList" :key="columnProps.title" class="dashboard-column">
				<div class="dashboard-column__header">
					<h3 class="dashboard-column__header-title"
						:title="columnProps.title"
						:aria-label="columnProps.title">
						{{ t('deck', columnProps.title) }}
					</h3>
				</div>
				<div class="dashboard-column__list">
					<Container :get-child-payload="payloadForColumn(columnProps.filter, columnProps.sort)"
						group-name="overview-columns"
						@drop="($event) => onDropCard(columnProps.filter, $event)">
						<Draggable v-for="card in getCardsForColumn(columnProps.filter, columnProps.sort)" :key="card.id">
							<CardItem :id="card.id" show-board-badge />
						</Draggable>
					</Container>
				</div>
			</div>
		</div>

		<GlobalSearchResults />
	</div>
</template>

<script>
import Controls from '../Controls.vue'
import CardItem from '../cards/CardItem.vue'
import { mapGetters } from 'vuex'
import GlobalSearchResults from '../search/GlobalSearchResults.vue'
import { Container, Draggable } from 'vue-smooth-dnd'
import moment from '@nextcloud/moment'

const FILTER_UPCOMING = 'upcoming'

const SUPPORTED_FILTERS = [
	FILTER_UPCOMING,
]

const COLUMN_PROPS_LIST = [
	{
		title: 'Overdue',
		filter: 'overdue',
	},
	{
		title: 'Today',
		filter: 'today',
	},
	{
		title: 'Tomorrow',
		filter: 'tomorrow',
	},
	{
		title: 'Next 7 days',
		filter: 'nextSevenDays',
	},
	{
		title: 'Later',
		filter: 'later',
	},
	{
		title: 'No due',
		filter: 'nodue',
		sort: false,
	},
]

export default {
	name: 'Overview',
	components: {
		GlobalSearchResults,
		Controls,
		CardItem,
		Container,
		Draggable,
	},
	props: {
		filter: {
			type: String,
			default: FILTER_UPCOMING,
		},
	},
	data() {
		return {
			loading: true,
			columnPropsList: COLUMN_PROPS_LIST,
		}
	},
	computed: {
		isValidFilter() {
			return SUPPORTED_FILTERS.indexOf(this.filter) > -1
		},
		filterDisplayName() {
			switch (this.filter) {
			case FILTER_UPCOMING:
				return t('deck', 'Upcoming cards')
			default:
				return ''
			}
		},
		...mapGetters(['assignedCardsDashboard']),
	},
	watch: {
		'$route.params.filter'() {
			this.getData()
		},
	},
	created() {
		this.getData()
	},
	methods: {
		async getData() {
			this.loading = true
			try {
				if (this.filter === FILTER_UPCOMING) {
					await this.$store.dispatch('loadUpcoming')
				}
			} catch (e) {
				console.error(e)
			}
			this.loading = false
		},
		filterCards(when) {
			return this.assignedCardsDashboard[when]
		},
		sortCards(cards) {
			if (!cards) {
				return null
			} else {
				return cards.toSorted((current, next) => {
					const currentDueDate = new Date(current.duedate)
					const nextDueDate = new Date(next.duedate)
					return currentDueDate - nextDueDate
				})
			}
		},
		getCardsForColumn(filter, sort) {
			const cards = this.filterCards(filter)
			if (!cards) {
				return []
			}
			return sort === false ? cards : this.sortCards(cards) || []
		},
		payloadForColumn(filter, sort) {
			return index => {
				const cards = this.getCardsForColumn(filter, sort)
				return cards ? cards[index] : null
			}
		},
		async onDropCard(targetFilter, event) {
			const { addedIndex, removedIndex, payload } = event
			if (addedIndex !== null) {
				const card = payload
				if (!card) return

				// Find the source filter
				let sourceFilter = null
				const assignedCards = this.assignedCardsDashboard
				for (const filter in assignedCards) {
					if (assignedCards[filter] && assignedCards[filter].some(c => c.id === card.id)) {
						sourceFilter = filter
						break
					}
				}

				if (!sourceFilter) return

				// Commit mutation to update UI immediately
				this.$store.commit('moveUpcomingCard', {
					cardId: card.id,
					sourceFilter,
					targetFilter,
					targetIndex: addedIndex,
				})

				// Calculate new duedate to save
				let newDueDate = null
				if (targetFilter === 'overdue') {
					newDueDate = moment().subtract(1, 'days').endOf('day').toISOString()
				} else if (targetFilter === 'today') {
					newDueDate = moment().endOf('day').toISOString()
				} else if (targetFilter === 'tomorrow') {
					newDueDate = moment().add(1, 'days').endOf('day').toISOString()
				} else if (targetFilter === 'nextSevenDays') {
					newDueDate = moment().add(3, 'days').endOf('day').toISOString()
				} else if (targetFilter === 'later') {
					newDueDate = moment().add(14, 'days').endOf('day').toISOString()
				} else if (targetFilter === 'nodue') {
					newDueDate = null
				}

				try {
					await this.$store.dispatch('updateCardDue', {
						...card,
						duedate: newDueDate,
					})
				} catch (e) {
					console.error(e)
					// If it fails, reload the upcoming cards
					this.$store.dispatch('loadUpcoming')
				}
			}
		},
	},

}
</script>

<style lang="scss" scoped>
@import './../../css/variables.scss';

.overview-wrapper {
	position: relative;
	width: 100%;
	height: 100%;
	display: flex;
	flex-direction: column;
}

.overview {
	position: relative;
	overflow-x: auto;
	flex-grow: 1;
	scrollbar-gutter: stable;
	display: flex;
	align-items: stretch;
	gap: $board-gap;
	padding: 0 $board-gap;

	.dashboard-column {
		display: flex;
		flex-direction: column;
		flex: 0 1 $card-max-width;
		min-width: $card-min-width;
		min-height: 0;

		.dashboard-column__header {
			display: flex;
			position: sticky;
			top: 0;
			height: var(--default-clickable-area);
			z-index: 100;
			margin-top: 0;
			background-color: var(--color-main-background);

			// Smooth fade out of the cards at the top
			&:before {
				content: '';
				display: block;
				position: absolute;
				width: 100%;
				height: 20px;
				top: 30px;
				inset-inline-start: 0px;
				z-index: 99;
				transition: top var(--animation-slow);
				background-image: linear-gradient(180deg, var(--color-main-background) 3px, rgba(255, 255, 255, 0) 100%);

				body.theme--dark & {
					background-image: linear-gradient(180deg, var(--color-main-background) 3px, rgba(0, 0, 0, 0) 100%);
				}
			}
		}

		.dashboard-column__header-title {
			display: flex;
			align-items: center;
			height: var(--default-clickable-area);
			margin: 0;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			padding: $card-padding;
			font-size: var(--default-font-size);
		}

		.dashboard-column__list {
			$margin-x: calc($stack-gap * -1);
			display: flex;
			flex-direction: column;
			padding: $stack-gap;
			margin: 0 $margin-x;
			overflow-y: auto;
			scrollbar-gutter: stable;
			flex-grow: 1;
		}

		:deep(.smooth-dnd-container.vertical) {
			display: flex;
			flex-direction: column;
			gap: $stack-gap;
			flex-grow: 1;
			min-height: 80px;
		}

		:deep(.smooth-dnd-container.vertical > .smooth-dnd-draggable-wrapper) {
			overflow: initial;
		}

		:deep(.smooth-dnd-container.vertical .smooth-dnd-draggable-wrapper) {
			height: auto;
		}
	}
}

</style>
