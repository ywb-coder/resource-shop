/* BizDate — 后台自定义日期选择器
   原生 <input type="date"> 的外观和弹层完全由系统决定，各平台长得都不一样，
   和 BizSelect 放一排会很突兀，所以自己画一个。值统一用 YYYY-MM-DD 字符串。 */
window.BizDate = {
  props: {
    modelValue:  { type: String,  default: '' },
    placeholder: { type: String,  default: '选择日期' },
    full:        { type: Boolean, default: false }
  },
  emits: ['update:modelValue', 'change'],
  template: `
    <div class="biz-date" :class="{ 'biz-date--open': open, 'biz-date--full': full }" ref="root">
      <div class="biz-date-trigger" @click.stop="toggle">
        <span :class="modelValue ? 'biz-date-val' : 'biz-date-ph'">{{ modelValue || placeholder }}</span>
        <i class="fa-solid fa-xmark biz-date-clear" v-if="modelValue" @click.stop="clear" title="清除"></i>
        <i class="fa-regular fa-calendar biz-date-icon" v-else></i>
      </div>

      <div class="biz-date-drop" v-show="open" @click.stop>
        <div class="biz-date-head">
          <button type="button" class="biz-date-nav" @click="shiftMonth(-1)" title="上个月">
            <i class="fa-solid fa-chevron-left"></i>
          </button>
          <span class="biz-date-title">{{ viewYear }} 年 {{ viewMonth + 1 }} 月</span>
          <button type="button" class="biz-date-nav" @click="shiftMonth(1)" title="下个月">
            <i class="fa-solid fa-chevron-right"></i>
          </button>
        </div>

        <div class="biz-date-week">
          <span v-for="w in weeks" :key="w">{{ w }}</span>
        </div>

        <div class="biz-date-grid">
          <button
            type="button"
            v-for="(cell, i) in cells"
            :key="i"
            class="biz-date-cell"
            :class="{ muted: !cell.inMonth, today: cell.isToday, active: cell.value === modelValue }"
            @click="pick(cell.value)"
          >{{ cell.day }}</button>
        </div>

        <div class="biz-date-foot">
          <button type="button" class="biz-date-link" @click="pick(todayStr)">今天</button>
          <button type="button" class="biz-date-link" @click="clear">清除</button>
        </div>
      </div>
    </div>
  `,
  data() {
    const base = this.parse(this.modelValue) || new Date();
    return {
      open: false,
      viewYear: base.getFullYear(),
      viewMonth: base.getMonth(),
      weeks: ['日', '一', '二', '三', '四', '五', '六']
    };
  },
  computed: {
    todayStr() { return this.format(new Date()); },
    cells() {
      const first = new Date(this.viewYear, this.viewMonth, 1);
      // 从这个月第一天所在那一周的周日开始铺，固定 6 行，避免弹层高度跳动
      const start = new Date(first);
      start.setDate(1 - first.getDay());

      const today = this.format(new Date());
      const list = [];
      for (let i = 0; i < 42; i++) {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        const value = this.format(d);
        list.push({
          day: d.getDate(),
          value,
          inMonth: d.getMonth() === this.viewMonth,
          isToday: value === today
        });
      }
      return list;
    }
  },
  watch: {
    // 外部改值（比如点了「重置」）时，日历跳到对应月份
    modelValue(v) {
      const d = this.parse(v);
      if (d) {
        this.viewYear = d.getFullYear();
        this.viewMonth = d.getMonth();
      }
    }
  },
  methods: {
    parse(str) {
      if (!str) return null;
      const m = String(str).match(/^(\d{4})-(\d{2})-(\d{2})$/);
      if (!m) return null;
      const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
      return isNaN(d.getTime()) ? null : d;
    },
    // 不能用 toISOString：那是 UTC，东八区会整体差一天
    format(d) {
      const p = n => String(n).padStart(2, '0');
      return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    },
    toggle() {
      const next = !this.open;
      // 和 BizSelect 共用一条广播，两种下拉之间也能互斥
      if (next) {
        document.dispatchEvent(new CustomEvent('biz-dropdown-open', { detail: this }));
        const d = this.parse(this.modelValue) || new Date();
        this.viewYear = d.getFullYear();
        this.viewMonth = d.getMonth();
      }
      this.open = next;
    },
    onOtherOpen(e) { if (e.detail !== this) this.open = false; },
    shiftMonth(step) {
      const d = new Date(this.viewYear, this.viewMonth + step, 1);
      this.viewYear = d.getFullYear();
      this.viewMonth = d.getMonth();
    },
    pick(value) {
      this.$emit('update:modelValue', value);
      this.$emit('change', value);
      this.open = false;
    },
    clear() {
      this.$emit('update:modelValue', '');
      this.$emit('change', '');
      this.open = false;
    },
    onOutside(e) {
      if (this.$refs.root && !this.$refs.root.contains(e.target)) this.open = false;
    }
  },
  mounted() {
    document.addEventListener('click', this.onOutside);
    document.addEventListener('biz-dropdown-open', this.onOtherOpen);
  },
  beforeUnmount() {
    document.removeEventListener('click', this.onOutside);
    document.removeEventListener('biz-dropdown-open', this.onOtherOpen);
  }
};
