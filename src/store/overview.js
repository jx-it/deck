/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import Vue from 'vue'
import Vuex from 'vuex'
import { OverviewApi } from '../services/OverviewApi.js'
import moment from '@nextcloud/moment'
Vue.use(Vuex)

const apiClient = new OverviewApi()
export default {
	state: {
		assignedCards: [],
		loading: false,
	},
	getters: {
		assignedCardsDashboard: state => {
			return state.assignedCards
		},
	},
	mutations: {
		setAssignedCards(state, assignedCards) {
			state.assignedCards = assignedCards
		},
		moveUpcomingCard(state, { cardId, sourceFilter, targetFilter, targetIndex }) {
			const sourceArray = state.assignedCards[sourceFilter]
			if (!sourceArray) return
			const cardIndex = sourceArray.findIndex(c => c.id === cardId)
			if (cardIndex === -1) return
			
			const [card] = sourceArray.splice(cardIndex, 1)
			
			if (targetFilter === 'overdue') {
				card.duedate = moment().subtract(1, 'days').endOf('day').toISOString()
			} else if (targetFilter === 'today') {
				card.duedate = moment().endOf('day').toISOString()
			} else if (targetFilter === 'tomorrow') {
				card.duedate = moment().add(1, 'days').endOf('day').toISOString()
			} else if (targetFilter === 'nextSevenDays') {
				card.duedate = moment().add(3, 'days').endOf('day').toISOString()
			} else if (targetFilter === 'later') {
				card.duedate = moment().add(14, 'days').endOf('day').toISOString()
			} else if (targetFilter === 'nodue') {
				card.duedate = null
			}
			
			if (!state.assignedCards[targetFilter]) {
				Vue.set(state.assignedCards, targetFilter, [])
			}
			state.assignedCards[targetFilter].splice(targetIndex, 0, card)
		},
		setLoading(state, promise) {
			state.loading = promise
		},
	},
	actions: {
		async loadUpcoming({ state, commit }) {
			if (state.loading) {
				return state.loading
			}
			const promise = (async () => {
				commit('setCurrentBoard', null)
				const assignedCards = await apiClient.get('upcoming')
				const assignedCardsFlat = Object.values(assignedCards).flat()
				for (const i in assignedCardsFlat) {
					commit('addCard', assignedCardsFlat[i])
				}
				commit('setAssignedCards', assignedCards)
				commit('setLoading', false)
			})()
			commit('setLoading', promise)
			return promise
		},
	},
}
