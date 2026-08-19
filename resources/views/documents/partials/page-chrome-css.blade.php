@page {
    margin: 175px 34px 110px;
}

body {
    margin: 0;
    padding: 0;
}

.items-table,
.items {
    table-layout: fixed;
    width: 100%;
}

.items-table td,
.items-table th,
.items td,
.items th {
    overflow-wrap: break-word;
    word-wrap: break-word;
}

.items-table thead,
.items thead {
    display: table-header-group;
}

.items-table tr,
.items tr {
    page-break-inside: auto;
}

.item-continuation td {
    border-top: 0;
}

.document-keep-together {
    page-break-inside: avoid;
}
