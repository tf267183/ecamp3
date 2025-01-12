<template>
  <ContentNodeCard class="ec-checklist-node" v-bind="$props">
    <template #outer>
      <DetailPane
        v-if="!disabled && !layoutMode"
        v-model="showDialog"
        icon="mdi-clipboard-list-outline"
        :title="$tc('components.activity.content.checklist.title')"
        :cancel-action="close"
        :cancel-visible="false"
      >
        <template #activator="{ on }">
          <button
            class="text-left mb-3 flex-grow-1 d-flex flex-column"
            :class="{ 'theme--light v-input--is-disabled': layoutMode }"
            :disabled="layoutMode"
            v-on="on"
          >
            <v-skeleton-loader v-if="!itemsLoaded" class="px-4 pb-4" type="paragraph" />
            <v-list-item v-else-if="activeChecklists.length === 0">
              <v-list-item-title>
                {{ $tc('global.button.edit') }}
              </v-list-item-title>
            </v-list-item>
            <ChecklistItems
              :active-checklists="activeChecklists"
              :layout-mode="layoutMode"
            />
          </button>
        </template>
        <div class="ma-n4">
          <v-expansion-panels multiple flat accordion>
            <v-expansion-panel
              v-for="{ checklist, items } in allChecklists"
              :key="checklist._meta.self"
            >
              <v-expansion-panel-header>
                <h3>
                  {{ checklist.name }}
                  <small class="font-weight-regular">
                    ({{
                      selectionContentNode.filter(
                        (item) =>
                          checkedItems.includes(item.id) &&
                          item.checklist()._meta.self === checklist?._meta?.self
                      ).length
                    }}
                    selected)
                  </small>
                </h3>
              </v-expansion-panel-header>
              <v-expansion-panel-content>
                <ol>
                  <ChecklistItem
                    v-for="{ item } in items.filter(({ item }) => item.parent == null)"
                    :key="item._meta.self"
                    :checklist="checklist"
                    :item="item"
                    @remove-item="removeItem"
                    @add-item="addItem"
                  />
                </ol>
              </v-expansion-panel-content>
            </v-expansion-panel>
          </v-expansion-panels>
        </div>
      </DetailPane>
      <ChecklistItems
        v-else
        class="mb-1"
        :active-checklists="activeChecklists"
        :layout-mode="layoutMode"
      />
    </template>
  </ContentNodeCard>
</template>

<script>
import ContentNodeCard from '@/components/activity/content/layout/ContentNodeCard.vue'
import { contentNodeMixin } from '@/mixins/contentNodeMixin.js'
import DetailPane from '@/components/generic/DetailPane.vue'
import ChecklistItem from './checklist/ChecklistItem.vue'
import ChecklistItems from './checklist/ChecklistItems.vue'
import { serverErrorToString } from '@/helpers/serverError.js'
import { debounce, isEqual, sortBy, uniq } from 'lodash'
import { computed } from 'vue'

export default {
  name: 'Checklist',
  components: { ChecklistItems, DetailPane, ContentNodeCard, ChecklistItem },
  mixins: [contentNodeMixin],
  provide() {
    return {
      checkedItems: computed(() => this.checkedItems),
    }
  },
  data() {
    return {
      savingRequestCount: 0,
      dirty: false,
      showDialog: false,
      checkedItems: [],
      uncheckedItems: [],
      errorMessages: null,
      debouncedSave: () => null,
      itemsLoaded: false,
    }
  },
  computed: {
    campChecklistItems() {
      if (!this.itemsLoaded) return []
      return (
        this.api.get().checklistItems({
          'checklist.camp': this.camp?._meta.self,
        }).items ?? []
      )
    },
    selectionContentNode() {
      return this.campChecklistItems.filter((item) =>
        this.contentNode
          .checklistItems()
          .items.some(({ _meta }) => _meta.self === item._meta.self)
      )
    },
    serverSelection() {
      return this.selectionContentNode.map((item) => item.id)
    },
    allChecklists() {
      return this.camp?.checklists()?.items.map((checklist) => ({
        checklist,
        items: this.campChecklistItems
          ?.filter((item) => item.checklist()._meta.self === checklist?._meta.self)
          .map((item) => ({
            item,
            parents: this.itemsLoaded ? this.getParents(item) : [],
          }))
          .sort((a, b) => {
            const aparents = [
              ...a.parents.map(({ position }) => position),
              a.item.position,
              -1,
            ]
            const bparents = [
              ...b.parents.map(({ position }) => position),
              b.item.position,
              -1,
            ]
            for (let i = 0; i < Math.min(aparents.length, bparents.length); i++) {
              if (aparents[i] !== bparents[i]) {
                return aparents[i] - bparents[i]
              }
            }
            return 0
          }),
      }))
    },
    activeChecklists() {
      return this.allChecklists
        .filter(({ checklist }) =>
          this.contentNode
            .checklistItems()
            .items.some((item) => checklist._meta.self === item?.checklist()._meta.self)
        )
        .map(({ checklist, items }) => ({
          checklist,
          items: items.filter(({ item }) => this.checkedItems.includes(item.id)),
        }))
    },
  },
  watch: {
    serverSelection: {
      async handler(newOptions, oldOptions) {
        if (isEqual(sortBy(newOptions), sortBy(oldOptions))) {
          return
        }

        // copy incoming data if not dirty or if incoming data is the same as local data
        if (!this.dirty || isEqual(sortBy(newOptions), sortBy(this.checkedItems))) {
          this.resetLocalData()
        }
      },
      immediate: true,
    },
  },
  created() {
    const DEBOUNCE_WAIT = 500
    this.debounceSave = debounce(this.save, DEBOUNCE_WAIT)
    this.camp.checklists()._meta.load.then(() => {
      this.api
        .get()
        .checklistItems({
          'checklist.camp': this.camp?._meta.self,
        })
        ._meta.load.then(() => {
          this.itemsLoaded = true
        })
    })
  },
  beforeDestroy() {
    this.checkedItems = null
    this.uncheckedItems = null
  },
  methods: {
    // get all parents of a given item
    getParents(item) {
      const parents = []
      let parent = item?.parent?.()
      while (parent) {
        parents.unshift(parent)
        parent = parent?.parent?.()
      }
      return parents
    },
    onInput() {
      this.dirty = true
      this.errorMessages = []

      this.debounceSave()
    },
    removeItem(item) {
      this.uncheckedItems.push(item)
      this.checkedItems = this.checkedItems.filter((i) => i !== item)
      this.onInput()
    },
    addItem(item) {
      this.checkedItems.push(item)
      this.onInput()
    },
    save() {
      this.savingRequestCount++
      this.contentNode
        .$patch({
          addChecklistItemIds: uniq(this.checkedItems),
          removeChecklistItemIds: uniq(this.uncheckedItems),
        })
        .catch((e) => this.errorMessages.push(serverErrorToString(e)))
        .finally(() => this.savingRequestCount--)
    },
    resetLocalData() {
      this.checkedItems = [...this.serverSelection]
      this.uncheckedItems = []
      this.dirty = false
    },
    close() {
      this.showDialog = false
    },
  },
}
</script>

<style scoped></style>
