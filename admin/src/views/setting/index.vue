<template>
    <el-tabs type="border-card" @tab-click="handleTabClick">
        <el-tab-pane label="Backup"><FormBackup ref="backup" /></el-tab-pane>
    </el-tabs>
</template>

<script>
import { onMounted, reactive, ref, toRefs } from 'vue'
import { ElMessage } from 'element-plus'
import { useRouter } from 'vue-router'
import api from '../../utils/api'
import { useTable, renderTableData } from '../../utils/table'
import FormBasic from './form-basic.vue'
import FormMain from './form-main.vue'
import FormBackup from './form-backup.vue'

export default {
    name: 'Setting',
    components: {
        FormBasic, FormMain, FormBackup
    },
    setup() {
        const multipleTable = ref(null)
        const router = useRouter()
        const backup = ref(null)
        const state = useTable()

        onMounted(() => {
            console.log('Setting onMounted')
            backup.value.listSetting()
        })
        const fetchTableData = async () => {
            state.loading = true
            let res = await api.listExam(state.query)
            renderTableData(res, state)
            state.loading = false
        }
        const handleAdd = () => {
            router.push({ name: 'exam-form' })
        }
        const handleEdit = (id) => {
            router.push({ path: '/exam-form', query: { id } })
        }
        const handleDelete = async (id) => {
            let res = await api.deleteExam(id)
            ElMessage.success(res.msg)
            state.query.page = 1;
            await fetchTableData()
        }
        const handleSelectionChange = (val) => {
            state.multipleSelection = val
        }
        const changePage = (val) => {
            state.query.page = val
            fetchTableData()
        }
        const handleTabClick = (val) => {
            console.log('handleTabClick', val)
        }
        return {
            ...toRefs(state),
            multipleTable,
            handleSelectionChange,
            handleAdd,
            handleEdit,
            handleDelete,
            handleTabClick,
            fetchTableData,
            changePage,
            backup,
        }
    }
}
</script>

<style scoped>

</style>
