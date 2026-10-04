<script>
export default {
    data() {
        return {
            filter: {
                customFilter: false,
                search: '',
                sortBy: null,
                defaultFilter: 'qVolume',
                defaultFilterDirection: 'asc',
                filterBy: null,
                filterDirection: null,
                type: 'all',
            },
        }
    },
    methods: {
        setFilter(filterBy, direction = false, initial = false) {

            if(!initial && this.filter.filterBy != filterBy) {
                this.filter.filterBy = filterBy;
                this.filter.filterDirection = this.filter.defaultFilterDirection;
                return;
            }

            this.filter.filterBy = filterBy;

            if(direction) {
                this.filter.filterDirection = direction;
            } else if(this.filter.filterDirection == "none") {
                this.filter.filterDirection = 'asc';
            } else if(this.filter.filterDirection == 'asc') {
                this.filter.filterDirection = 'desc';
            } else {
                this.filter.filterDirection = 'none';
                this.filter.filterBy = this.filter.defaultFilter;
            }
        },
        setSort(sortBy) {
            this.filter.type = 'all';
            this.filter.sortBy = sortBy;
        },
        setType(type, direction = false, isCustom = false) {
            this.filter.type = type;
            this.filter.customFilter = isCustom;
        },
    }
};
</script>