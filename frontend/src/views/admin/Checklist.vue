<template>
  <ChecklistDetail :checklist="checklist" />
</template>

<script>
import ChecklistDetail from '@/components/checklist/ChecklistDetail.vue'

export default {
  name: 'AdminChecklist',
  components: {
    ChecklistDetail,
  },
  props: {
    checklist: {
      type: Object,
      default: null,
      required: false,
    },
  },
}
</script>
