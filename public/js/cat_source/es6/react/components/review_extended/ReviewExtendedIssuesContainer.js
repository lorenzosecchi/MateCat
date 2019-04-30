let ReviewExtendedIssue =  require("./ReviewExtendedIssue").default;
let WrapperLoader =         require("../../common/WrapperLoader").default;
let SegmentConstants = require('../../constants/SegmentConstants');
class ReviewExtendedIssuesContainer extends React.Component {

    constructor(props) {
        super(props);
        this.state = {
            lastIssueAdded: null,
            visible: true
        };
        this.issueFlatCategories = JSON.parse(config.lqa_flat_categories);
        this.issueNestedCategories = JSON.parse(config.lqa_nested_categories).categories;
        this.is2ndPassReviewEnabled =  (config.secondRevisionsCount && config.secondRevisionsCount > 0);
    }

    parseIssues() {
        let issuesObj = {}
        this.props.issues.forEach( issue => {
            let cat = this.findCategory(issue.id_category);
            let id = (this.isSubCategory(cat)) ? cat.id_parent: cat.id;

            if (!issuesObj[id]) {
                issuesObj[id] = [];
            }
            issuesObj[id].push(issue);
        });
        return issuesObj;
    }

    findCategory( id ) {
        return this.issueFlatCategories.find( category => {
            return id == category.id
        } )
    }

    isSubCategory( category ) {
        return !_.isNull(category.id_parent);
    }

    thereAreSubcategories() {
        return this.issueNestedCategories[0].subcategories && this.issueNestedCategories[0].subcategories.length > 0;
    }

    getSubCategoriesHtml() {
        let parsedIssues = this.parseIssues();
        let htmlR1 = [], htmlR2 = [];
        _.each(parsedIssues, (issuesList, id) =>  {
            let cat = this.findCategory(id);
            let issues = this.getIssuesSortedComponentList(issuesList);
            if ( issues.r1.length > 0 ) {
                htmlR1.push(<div key={cat.id}>
                    <div className="re-item-head pad-left-5">{cat.label}</div>
                    {issues.r1}
                </div>);
            }
            if ( issues.r2.length > 0 ) {
                htmlR2.push(<div key={cat.id}>
                    <div className="re-item-head pad-left-5">{cat.label}</div>
                    {issues.r2}
                </div>);
            }
        });
        if ( this.is2ndPassReviewEnabled ) {
            return <div>
                <div className="ui top attached tabular menu" ref={(tabs)=>this.tabs=tabs}>
                    <a className={classnames("item active", htmlR1.length === 0 && "disabled")} data-tab="r1">R1 issues</a>
                    <a className={classnames("item", htmlR2.length === 0 && "disabled")} data-tab="r2">R2 issues</a>
                </div>

                <div className={classnames("ui bottom attached tab segment active", htmlR1.length === 0 && "disabled")} data-tab="r1" style={{padding: '0px', width: '99.5%', maxHeight: '200px', overflowY: 'auto'}}>
                    {htmlR1}
                </div>
                <div className={classnames("ui bottom attached tab segment", htmlR2.length === 0 && "disabled")} data-tab="r2" style={{padding: '0px', width: '99.5%', maxHeight: '200px', overflowY: 'auto'}}>
                    {htmlR2}
                </div>
            </div>;
        } else {
            return htmlR1
        }
    }

    getCategoriesHtml() {
        let issues;

        if (this.props.issues.length > 0 ) {
            issues = this.getIssuesSortedComponentList(this.props.issues)
        }
        if ( this.is2ndPassReviewEnabled ) {
            return <div>
                    <div className="ui top attached tabular menu" ref={(tabs)=>this.tabs=tabs}>
                        <a className={classnames("item active", issues.r1.length === 0 && "disabled")} data-tab="r1">R1 issues</a>
                        <a className={classnames("item", issues.r2.length === 0 && "disabled")} data-tab="r2">R2 issues</a>
                    </div>

                    <div className={classnames("ui bottom attached tab segment active", issues.r1.length === 0 && "disabled")} data-tab="r1" style={{padding: '0px', width: '99.5%', maxHeight: '200px', overflowY: 'auto'}}>
                        {issues.r1}
                    </div>
                    <div className={classnames("ui bottom attached tab segment", issues.r2.length === 0 && "disabled")} data-tab="r2" style={{padding: '0px', width: '99.5%', maxHeight: '200px', overflowY: 'auto'}}>
                        {issues.r2}
                    </div>
                </div>;
        } else {
            return <div>
                        <div className="re-item-head pad-left-1">Issues found</div>
                        {issues.r1}
                    </div>
        }

    }

    getIssuesSortedComponentList(list) {
        let issuesR1 = [], issuesR2 = [];
        let sorted_issues = list.sort(function(a, b) {
            a = new Date(a.created_at);
            b = new Date(b.created_at);
            return a>b ? -1 : a<b ? 1 : 0;
        });

        _.forEach(sorted_issues, (item)=>{
            if ( item.revision_number === 2 ) {
                issuesR2.push(<ReviewExtendedIssue
                    lastIssueId={this.state.lastIssueAdded}
                    sid={this.props.segment.sid}
                    isReview={this.props.isReview}
                    issue={item}
                    key={item.id}
                    changeVisibility={this.changeVisibility.bind(this)}
                />);
            } else {
                issuesR1.push(<ReviewExtendedIssue
                    lastIssueId={this.state.lastIssueAdded}
                    sid={this.props.segment.sid}
                    isReview={this.props.isReview}
                    issue={item}
                    key={item.id}
                    changeVisibility={this.changeVisibility.bind(this)}
                />)
            }
        });

        return {r1: issuesR1, r2: issuesR2};
    }

    changeVisibility(id, visible) {
        let issues = this.props.issues.slice();
        let index = _.findIndex(issues, function ( item ) {
            return item.id == id;
        });
        issues[index].visible = visible;

        let visibleIssues = _.filter(this.props.issues, function ( item ) {
            return _.isUndefined(item.visible) || item.visible;
        });
        if (visibleIssues.length === 0) {
            this.setState({
                visible: false
            });
        } else {
            this.setState({
                visible: true
            });
        }
    }

    setLastIssueAdded(sid, id) {
        if ( sid === this.props.segment.sid ) {
            setTimeout((  ) => {
                SegmentActions.openIssueComments(this.props.segment.sid, id);
            }, 200);

        }
    }

    componentDidMount() {
        SegmentStore.addListener(SegmentConstants.ISSUE_ADDED, this.setLastIssueAdded.bind(this));
        $(this.tabs).find('.item:not(.disabled)').tab();
    }

    componentWillUnmount() {
        SegmentStore.removeListener(SegmentConstants.ISSUE_ADDED, this.setLastIssueAdded);
        //Undo notification
        APP.removeAllNotifications();
    }

    componentDidUpdate(prevProps, prevState) {
        if ( prevProps.issues.length < this.props.issues.length ) {
            this.setState({
                visible: true
            });
        }
        $(this.tabs).find('.item:not(.disabled)').tab();
    }

    render () {

        if(this.props.issues.length > 0){

            let html;
            if (this.thereAreSubcategories()) {
                html = this.getSubCategoriesHtml();
            } else {
                html = this.getCategoriesHtml()
            }
            let classNotVisible = (!this.state.visible) ? 're-issues-box-empty' : ''
            return <div className={"re-issues-box re-created " + classNotVisible}>
                    {this.props.loader ? <WrapperLoader /> : null}
                    <div className={classnames("re-list issues", this.is2ndPassReviewEnabled && "no-scroll")}>
                        {html}
                    </div>
            </div>;
        }
        return "";

    }
}

export default ReviewExtendedIssuesContainer;
